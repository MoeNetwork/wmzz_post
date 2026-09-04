<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }

require_once dirname(__FILE__) . '/wmzz_post_func.php';

/**
 * 自动灌水计划任务（一次一条 + 随机间隔，模拟真人节奏）。
 * 调度来源：云签到 do.php -> cron::runall()（系统 crontab 每分钟执行一次 php do.php）。
 * 规则：
 *   1. 每日补额：某用户 lastdo != 今天 时，其所有目标的 remain 重置为该用户的 num。
 *   2. 一次只回一条：每个调度周期随机挑一个“有额度且已到间隔时间”的目标发送；发完后，本轮其余到期目标
 *      会被统一重排到各自“固定底数 gap + 随机区间”后的时刻，保证不同帖子的回帖也彼此隔开、不扎堆。
 *   3. 每次成功回帖后，该帖下一次可发时间 = 该账号固定底数 gap(秒) + 管理员后台随机区间内的一个随机值
 *      （未配置区间时为默认 60~180 秒），每次都重新随机，因此每条回帖的间隔都不一样。
 *   4. 失败后目标退避并计入当日连续失败；连续失败 3 次当天不再重试。
 *   5. 与手动“测试回帖”完全隔离：测试不经过本函数、不扣 remain。
 */
function cron_wmzz_post()
{
	global $m;
	wmzz_ensure_schema();
	$now   = time();
	$today = date('Y-m-d');

	$set = unserialize(option::get('plugin_wmzz_post'));
	if (!is_array($set)) {
		$set = array();
	}
	$rng    = wmzz_interval_range($set); // 发帖随机间隔区间(秒)，未设置时为默认 60~180
	$rmin   = $rng['min'];
	$rmax   = $rng['max'];
	$device = (isset($set['device']) && in_array(intval($set['device']), array(1, 2, 4))) ? intval($set['device']) : 2;

	$did_refill = false;

	// ---- 1) 每日补额：跨天 / 首次配置立即生效 ----
	$sy = $m->query("SELECT `uid`,`num` FROM `" . DB_PREFIX . "wmzz_post` WHERE `lastdo` != '{$today}';");
	while ($sx = $m->fetch_array($sy)) {
		$u = intval($sx['uid']);
		$n = intval($sx['num']);
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $n . ', `try_ts` = 0, `fails` = 0, `status` = 0, `msg` = \'\' WHERE `uid` = ' . $u);
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post` SET `lastdo` = \'' . $today . '\' WHERE `uid` = ' . $u);
		wmzz_log("wmzz_post cron refill uid={$u} remain={$n}");
		$did_refill = true;
	}

	// ---- 2) 有额度且已到点的目标数 ----
	$count = $m->once_fetch_array("SELECT COUNT(*) AS `c` FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `remain` > 0 AND `try_ts` <= {$now};");
	$due   = isset($count['c']) ? intval($count['c']) : 0;
	if ($due <= 0) {
		if ($did_refill) {
			wmzz_log('wmzz_post cron end (refilled; no target with remaining quota)');
		}
		return null;
	}

	wmzz_log("wmzz_post cron start one target (due={$due})");
	// 一次只回一条：随机挑一个已到点的目标
	$y = rand_row(DB_PREFIX . 'wmzz_post_data', 'id', 1, "`remain` > 0 AND `try_ts` <= {$now}");
	if (isset($y['url'])) { // 只有一条记录的兼容方案
		$y = array(0 => $y);
	}
	$ok_cnt = 0;
	$fail_cnt = 0;
	foreach ($y as $x) {
		$xid = intval($x['id']);
		$xu  = intval($x['uid']);
		if (empty($x['pid']) || empty($xu)) {
			continue;
		}
		// 抢占：避免并行进程对同一目标重复发送（置 try_ts 为将来，谁先置谁处理）
		$claim = $now + 600;
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `try_ts` = ' . $claim . ' WHERE `id` = ' . $xid . ' AND `remain` > 0 AND `try_ts` <= ' . $now);
		$chk = $m->once_fetch_array('SELECT `try_ts` FROM `' . DB_PREFIX . 'wmzz_post_data` WHERE `id` = ' . $xid);
		if (!isset($chk['try_ts']) || $chk['try_ts'] != $claim) {
			wmzz_log("wmzz_post skip concurrent uid={$xu} id={$xid}");
			continue;
		}

		$u    = $m->once_fetch_array("SELECT * FROM `" . DB_PREFIX . "wmzz_post` WHERE `uid` = '{$xu}'");
		$cont = (isset($u['cont']) && $u['cont'] !== '') ? @unserialize($u['cont']) : array();
		if (empty($cont) || !is_array($cont) || empty(trim(implode('', $cont)))) {
			$cont = array('+3');
		}
		$content       = rand_array($cont);
		$remain_before = intval($x['remain']);

		wmzz_log("wmzz_post sending uid={$xu} tid={$x['url']} remain={$remain_before}");
		$res = wmzz_post_send($xu, $x['url'], $x['pid'], $content, $device, $x['kw'], intval($x['fid']));

		if (isset($res['status']) && $res['status'] == '1') {
			// 只有接口确认成功才扣额
			$newremain = max(0, $remain_before - 1);
			// 下一次间隔 = 账号固定底数 gap(秒) + 随机区间内随机值，每次都重新随机
			$gbase = isset($u['gap']) ? max(0, intval($u['gap'])) : 0;
			$gap   = $gbase + mt_rand($rmin, $rmax);
			$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $newremain . ', `status` = 1, `msg` = \'\', `try_ts` = ' . ($now + $gap) . ', `fails` = 0 WHERE `id` = ' . $xid);
			wmzz_log("wmzz_post success uid={$xu} tid={$x['url']} remain={$newremain} next_gap={$gap}s(base={$gbase}s+rand{$rmin}~{$rmax}s)");
			$ok_cnt++;
			// 其余到期目标统一重排到各自随机时刻 → 保证一次一条、随机错开
			$pushed = wmzz_reschedule_due($xid, $now, $rmin, $rmax);
			if ($pushed > 0) {
				wmzz_log("wmzz_post rescheduled others={$pushed} (rand {$rmin}~{$rmax}s each)");
			}
		} else {
			// 失败：remain 不扣，记录真实错误；连续失败 3 次则当天不再重试，避免反复空打接口
			$code = (string)(isset($res['status']) ? $res['status'] : '-1');
			$err  = (string)(isset($res['msg']) ? $res['msg'] : '发送失败');
			$scode  = is_numeric($code) ? intval($code) : -1;
			$fails  = (isset($x['fails']) ? intval($x['fails']) : 0) + 1;
			$next   = ($fails >= 3) ? (strtotime($today . ' 23:59:59') + 60) : ($now + 300);
			$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `status` = ' . $scode . ', `msg` = \'' . addslashes($err) . '\', `try_ts` = ' . $next . ', `fails` = ' . $fails . ' WHERE `id` = ' . $xid);
			wmzz_log("wmzz_post send failed uid={$xu} tid={$x['url']} code={$code} err={$err} remain unchanged={$remain_before} attempts={$fails}/3");
			$fail_cnt++;
		}
	}
	wmzz_log("wmzz_post cron end ok={$ok_cnt} fail={$fail_cnt}");
	return null;
}
