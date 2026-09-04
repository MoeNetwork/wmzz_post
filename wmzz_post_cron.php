<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }

require_once dirname(__FILE__) . '/wmzz_post_func.php';

/**
 * 自动灌水计划任务（全局串行 + 随机间隔，模拟真人节奏）。
 * 调度来源：云签到 do.php -> cron::runall()（系统 crontab 每分钟执行一次 php do.php）。
 * 规则：
 *   1. 每日补额：某用户 lastdo != 今天 时，其所有目标的 remain 重置为该用户的 num。
 *   2. 一次只回一条，且全站共用一个“时钟”：
 *      - 每次（无论对哪个帖）尝试回帖之后，会把一个全局时间戳 gate 推到
 *        now + 随机间隔；此后到 gate 之前，任何帖子都不再发包（含失败重试），
 *        因此不同帖子的回帖之间也强制隔开随机间隔，不会出现“两个帖一前一后紧挨着发”。
 *      - 间隔 = 该账号固定底数 gap(秒) + 管理员后台随机区间内的随机值
 *        （未配置区间时为默认 60~180 秒），每次尝试都重新随机。
 *   3. 用 GET_LOCK 防并发：同一时刻即使有多个任务进程，也只有一个能发包。
 *   4. 失败后目标退避并计入当日连续失败；连续失败 3 次当天不再重试。
 *   5. 与手动“测试回帖”完全隔离：测试不经过本函数、不扣 remain、不受全局时钟限制。
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

	// ---- 3) 并发锁：同一时刻只允许一个任务进程发包 ----
	$lk = $m->once_fetch_array("SELECT GET_LOCK('wmzz_post_send', 0) AS g;");
	if (!is_array($lk) || !isset($lk['g']) || (string)$lk['g'] !== '1') {
		return null; // 另一个任务进程正在发包，本分钟直接跳过
	}

	// ---- 4) 全局时钟：还没到允许发包的时间就整分钟跳过（不同帖子之间也隔开） ----
	$gate = wmzz_gate_read();
	if ($gate > $now) {
		wmzz_log("wmzz_post pacing: wait until " . date('Y-m-d H:i:s', $gate) . " (global random interval)");
		$m->query("SELECT RELEASE_LOCK('wmzz_post_send');");
		return null;
	}

	wmzz_log("wmzz_post cron start one target (due={$due})");
	// 一次只回一条：随机挑一个已到点的目标
	$y = rand_row(DB_PREFIX . 'wmzz_post_data', 'id', 1, "`remain` > 0 AND `try_ts` <= {$now}");
	if (empty($y)) {
		$m->query("SELECT RELEASE_LOCK('wmzz_post_send');");
		return null;
	}
	if (isset($y['url'])) { // 只有一条记录的兼容方案
		$y = array(0 => $y);
	}
	$ok_cnt = 0;
	$fail_cnt = 0;
	$gate_new = 0;
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
			// 间隔 = 账号固定底数 gap(秒) + 随机区间内随机值；至少 60 秒，避免同分钟连续发包
			$gbase = isset($u['gap']) ? max(0, intval($u['gap'])) : 0;
			$delay = max(60, $gbase + mt_rand($rmin, $rmax));
			$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $newremain . ', `status` = 1, `msg` = \'\', `try_ts` = ' . ($now + $delay) . ', `fails` = 0 WHERE `id` = ' . $xid);
			wmzz_log("wmzz_post success uid={$xu} tid={$x['url']} remain={$newremain} next_gap={$delay}s(base={$gbase}s+rand{$rmin}~{$rmax}s)");
			$ok_cnt++;
			$gate_new = $now + $delay;
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
			// 失败也推进全局时钟（仅按随机区间），避免每分钟都空试一次被继续盯上
			$gate_new = $now + max(60, mt_rand($rmin, $rmax));
		}
	}
	// ---- 5) 推进全局时钟：此后 gate 之前，无论哪个帖都不再发 ----
	if ($gate_new > 0) {
		wmzz_gate_write($gate_new);
	}
	$m->query("SELECT RELEASE_LOCK('wmzz_post_send');");
	wmzz_log("wmzz_post cron end ok={$ok_cnt} fail={$fail_cnt} next_at=" . date('Y-m-d H:i:s', $gate_new));
	return null;
}
