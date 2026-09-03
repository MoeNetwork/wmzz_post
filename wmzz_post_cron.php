<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }

require_once dirname(__FILE__) . '/wmzz_post_func.php';

/**
 * 自动灌水计划任务。
 * 调度来源：云签到 do.php -> cron::runall()（系统 crontab 每分钟执行一次 php do.php）。
 * 规则：
 *   1. 每日补额：某用户 lastdo != 今天 时，其所有目标的 remain 重置为该用户的 num。
 *   2. 只有【贴吧接口明确返回成功】才扣减 remain 一次；失败 remain 不动。
 *   3. 每次成功回帖后，该目标至少间隔 minint 分钟（默认 5，+轻微随机）才允许下一条自动回帖。
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
	$rem    = (isset($set['rem']) && intval($set['rem']) > 0) ? intval($set['rem']) : 1;
	$sleep  = isset($set['sleep']) ? intval($set['sleep']) : 0;
	$device = (isset($set['device']) && in_array(intval($set['device']), array(1, 2, 4))) ? intval($set['device']) : 2;
	// 最小回帖间隔（分钟）。默认 5；可在插件配置里用 minint 覆盖（0 或缺失按 5 处理）。
	$minint = (isset($set['minint']) && intval($set['minint']) > 0) ? intval($set['minint']) : 5;
	$pace   = $minint * 60 + mt_rand(0, 60); // 每次成功后的下一次可自动回帖时间（秒），带轻微随机避免绝对周期

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

	// ---- 2) 领取今日仍有额度、且不在退避期内的目标 ----
	$count = $m->once_fetch_array("SELECT COUNT(*) AS `c` FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `remain` > 0 AND `try_ts` <= {$now};");
	$need  = isset($count['c']) ? intval($count['c']) : 0;
	if ($need > $rem) {
		$need = $rem;
	}
	if ($need <= 0) {
		if ($did_refill) {
			wmzz_log('wmzz_post cron end (refilled; no target with remaining quota)');
		}
		return null;
	}

	wmzz_log("wmzz_post cron start targets={$need}");
	$y = rand_row(DB_PREFIX . 'wmzz_post_data', 'id', $need, "`remain` > 0 AND `try_ts` <= {$now}");
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
		$content      = rand_array($cont);
		$remain_before = intval($x['remain']);

		wmzz_log("wmzz_post sending uid={$xu} tid={$x['url']} remain={$remain_before}");
		$res = wmzz_post_send($xu, $x['url'], $x['pid'], $content, $device, $x['kw'], intval($x['fid']));

		if (isset($res['status']) && $res['status'] == '1') {
			// 只有接口确认成功才扣额
			$newremain = max(0, $remain_before - 1);
			// 成功后按最小间隔限速：本目标下次自动回帖至少等 pace 秒之后
			$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $newremain . ', `status` = 1, `msg` = \'\', `try_ts` = ' . ($now + $pace) . ', `fails` = 0 WHERE `id` = ' . $xid);
			wmzz_log("wmzz_post success uid={$xu} tid={$x['url']} remain={$newremain} next_min={$minint}m");
			$ok_cnt++;
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
		if ($sleep > 0) {
			sleep($sleep);
		}
	}
	wmzz_log("wmzz_post cron end ok={$ok_cnt} fail={$fail_cnt}");
	return null;
}
