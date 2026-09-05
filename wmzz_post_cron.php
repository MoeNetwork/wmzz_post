<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }

require_once dirname(__FILE__) . '/wmzz_post_func.php';

/**
 * 自动灌水计划任务（全局串行 + 随机间隔 + 双通道自动切换 + 递增强退避）。
 * 调度来源：云签到 do.php -> cron::runall()（系统 crontab 每分钟执行一次 php do.php）。
 * 规则：
 *   1. 每日补额：某用户 lastdo != 今天 时，其所有目标的 remain 重置为该用户的 num。
 *   2. 一次只回一条，全站共用一个“时钟”：
 *      - 任意一条（无论对哪个帖）成功后，把全局时间戳 gate 推到 now + 账号固定底数(gap) + 随机区间，
 *        到点前任何帖子都不再发 —— 因此不同帖子的回帖之间也强制隔开随机间隔，不会紧挨着连发。
 *   3. 多通道发帖：网页端(有 STOKEN 时) 与 客户端 type 4/2/1/3 按顺序尝试，哪个成功用哪个（一次最多成功一条）。
 *   4. 被拒退避：所有通道都被接口明确拒绝时，连续被拒次数 +1，并把 gate 推到 now + 递增随机退避，
 *      退避区间由管理员自定义（back_min~back_max 分钟，未设默认 20~150）：第1次取下限段、第2次取中段、
 *      第3次起取上限段（都随机、不断增大）；成功一条即清零恢复短节奏。不再使用旧的“同一目标失败3次当天停”。
 *   5. 每小时上限：管理员可设“每小时最多自动回帖 x 条”(0=不限)；到顶后本小时剩余时间不再发，整点自动解锁。
 *   6. 并发锁 GET_LOCK 防多进程同时发包。
 *   7. 与手动“测试回帖”完全隔离：测试不经过本函数、不扣 remain、不受时钟/退避/配额限制。
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
	$hlim   = isset($set['hour']) ? max(0, intval($set['hour'])) : 0;  // 每小时自动回帖上限，0=不限
	$device = (isset($set['device']) && in_array(intval($set['device']), array(1, 2, 3, 4))) ? intval($set['device']) : 4;
	// 被拒后退避区间（分钟），可由管理员自定义；未配置时默认 20~150
	$bmin = (isset($set['back_min']) && intval($set['back_min']) > 0) ? intval($set['back_min']) : 20;
	$bmax = (isset($set['back_max']) && intval($set['back_max']) > 0) ? intval($set['back_max']) : 150;

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

	// ---- 4) 每小时上限检查（只统计成功条数，跨小时自动归零） ----
	if ($hlim > 0) {
		$hk = date('YmdH', $now);
		$hv = wmzz_hour_read();
		if ($hv['key'] !== $hk) {
			$hv = array('key' => $hk, 'count' => 0);
			wmzz_hour_write($hk, 0);
		}
		if ($hv['count'] >= $hlim) {
			$boundary = $now - ($now % 3600) + 3600; // 下一个整点
			wmzz_gate_write($boundary + 60);
			wmzz_log("wmzz_post hourly cap reached {$hv['count']}/{$hlim}, pause until " . date('Y-m-d H:i', $boundary + 60));
			$m->query("SELECT RELEASE_LOCK('wmzz_post_send');");
			return null;
		}
	}

	// ---- 5) 全局时钟：还没到允许发包的时间就跳过（长退避时安静等待，避免刷屏） ----
	$gate = wmzz_gate_read();
	if ($gate > $now) {
		if (($gate - $now) <= 90) {
			wmzz_log("wmzz_post pacing: resume at " . date('Y-m-d H:i:s', $gate));
		}
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
		$resChannel = isset($res['channel']) ? $res['channel'] : '?';
		$resTried   = isset($res['tried']) ? $res['tried'] : '-';

		if (isset($res['status']) && $res['status'] == '1') {
			// 只有接口确认成功才扣额
			$newremain = max(0, $remain_before - 1);
			// 正常间隔 = 账号固定底数 gap(秒) + 随机区间内随机值；至少 60 秒，避免同分钟连续发包
			$gbase = isset($u['gap']) ? max(0, intval($u['gap'])) : 0;
			$delay = max(60, $gbase + mt_rand($rmin, $rmax));
			$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $newremain . ', `status` = 1, `msg` = \'\', `try_ts` = ' . ($now + $delay) . ', `fails` = 0 WHERE `id` = ' . $xid);
			// 成功即清零全局“连续被拒次数”，恢复短节奏
			if (wmzz_fails_read() > 0) {
				wmzz_fails_write(0);
			}
			// 累计本小时成功条数
			if ($hlim > 0) {
				$hk2 = date('YmdH', $now);
				$hv2 = wmzz_hour_read();
				if ($hv2['key'] !== $hk2) {
					$hv2 = array('key' => $hk2, 'count' => 0);
				}
				wmzz_hour_write($hk2, $hv2['count'] + 1);
			}
			wmzz_gate_write($now + $delay);
			wmzz_log("wmzz_post success ch={$resChannel} uid={$xu} tid={$x['url']} remain={$newremain} next_gap={$delay}s(base={$gbase}s+rand{$rmin}~{$rmax}s) tried={$resTried}");
			$ok_cnt++;
			$gate_new = $now + $delay;
		} else {
			$code = (string)(isset($res['status']) ? $res['status'] : '-1');
			$err  = (string)(isset($res['msg']) ? $res['msg'] : '发送失败');
			$err  = addslashes($err);
			$errType = isset($res['err_type']) ? $res['err_type'] : '';
			$refused = !empty($res['refused']);

			if ($errType === 'notfound') {
				// 帖子不存在：目标级问题，可无视 —— 标记并清零该目标当天额度，不再反复重试，也不阻塞其它目标
				$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = 0, `status` = ' . (is_numeric($code) ? intval($code) : -1) . ', `msg` = \'帖子不存在（已跳过，可删除该目标）\', `try_ts` = ' . (strtotime($today . ' 23:59:59') + 60) . ' WHERE `id` = ' . $xid);
				wmzz_log("wmzz_post notfound ch={$resChannel} uid={$xu} tid={$x['url']} -> marked not-exist, quota cleared tried={$resTried}");
				$fail_cnt++;
			} elseif ($errType === 'muted') {
				// 被吧务禁言：账号级 —— 清零该账号全部目标额度并标记
				$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = 0, `status` = ' . (is_numeric($code) ? intval($code) : -1) . ', `msg` = \'已被禁言（额度已清零）\', `try_ts` = ' . (strtotime($today . ' 23:59:59') + 60) . ' WHERE `uid` = ' . $xu);
				wmzz_log("wmzz_post muted uid={$xu} -> all targets quota cleared tried={$resTried}");
				$fail_cnt++;
			} elseif ($refused) {
				// 风控/验证码等（各端均被接口明确拒绝）：连续被拒次数 +1，进入递增随机退避（按管理员区间小→中→大段），该帖排后
				$fails = wmzz_fails_read() + 1;
				wmzz_fails_write($fails);
				$wait = wmzz_backoff_sec($fails, $bmin, $bmax);
				$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `status` = ' . (is_numeric($code) ? intval($code) : -1) . ', `msg` = \'' . $err . '\', `try_ts` = ' . ($now + $wait) . ' WHERE `id` = ' . $xid);
				wmzz_gate_write($now + $wait);
				wmzz_log("wmzz_post refused ch={$resChannel} uid={$xu} tid={$x['url']} code={$code} err={$err} consecutive={$fails} backoff~" . intval($wait / 60) . "min tried={$resTried}");
				$fail_cnt++;
				$gate_new = $now + $wait;
			} else {
				// 配置/登录态类问题（非风控拒绝）：不累计全局拒绝、不推进全局时钟，仅该目标半小时后重试（排后）
				$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `status` = ' . (is_numeric($code) ? intval($code) : -1) . ', `msg` = \'' . $err . '\', `try_ts` = ' . ($now + 1800) . ' WHERE `id` = ' . $xid);
				wmzz_log("wmzz_post config-fail ch={$resChannel} uid={$xu} tid={$x['url']} err={$err} retry-in-30min tried={$resTried}");
				$fail_cnt++;
			}
		}
	}
	$m->query("SELECT RELEASE_LOCK('wmzz_post_send');");
	if ($gate_new > 0) {
		wmzz_log("wmzz_post cron end ok={$ok_cnt} fail={$fail_cnt} next_at=" . date('Y-m-d H:i:s', $gate_new));
	} else {
		wmzz_log("wmzz_post cron end ok={$ok_cnt} fail={$fail_cnt}");
	}
	return null;
}
