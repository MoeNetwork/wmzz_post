<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }

/**
 * wmzz_post 公共运行函数
 * 由 wmzz_post.php / wmzz_post_cron.php / wmzz_post_show.php 共同引用。
 */

/**
 * 写运行日志（追加到插件目录 wmzz_post_cron.log，自动轮转）
 * 同一分钟内同一条日志只写一次，避免刷屏。
 */
function wmzz_log($line)
{
	static $guard = array();
	$key = md5($line . '#' . date('YmdHi'));
	if (isset($guard[$key])) {
		return;
	}
	$guard[$key] = true;
	$file = dirname(__FILE__) . '/wmzz_post_cron.log';
	$line = '[' . date('Y-m-d H:i:s') . '] ' . $line;
	if (!file_exists($file) || @filesize($file) < 512 * 1024) {
		@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
	} else {
		@file_put_contents($file, $line . PHP_EOL, LOCK_EX); // 超限后重置文件
	}
}

/**
 * 确保表结构包含运行所需新列（幂等，兼容老版本表）
 */
function wmzz_ensure_schema()
{
	static $done = false;
	if ($done) {
		return;
	}
	$done = true;
	global $m;
	if (!is_object($m) || !defined('DB_PREFIX') || !defined('DB_NAME')) {
		return;
	}
	$t = '`' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data`';
	@$m->query("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS `try_ts` int(11) unsigned NOT NULL DEFAULT 0");
	@$m->query("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS `test_at` int(11) unsigned NOT NULL DEFAULT 0");
	@$m->query("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS `fails` int(11) NOT NULL DEFAULT 0");
	@$m->query("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS `kw` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL");
	@$m->query("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS `fid` bigint(20) NOT NULL DEFAULT 0");
	// 每用户基础间隔（秒）：wmzz_post.gap，供 cron 计算“固定底数 gap + 后台随机区间”的两次回帖间隔
	$tu = '`' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post`';
	@$m->query("ALTER TABLE {$tu} ADD COLUMN IF NOT EXISTS `gap` int(11) NOT NULL DEFAULT 0");
}

/**
 * 对指定帖子发送一条回复
 * 通道：https://tieba.baidu.com/c/c/post/add
 * 说明：旧版本通过手机网页版解析帖子参数再发帖，网页通道已被贴吧风控/下线，
 *       此处改为直接调用主域客户端接口(已实测可成功发送)，参数经 ksort + tiebaclient!!! 签名。
 * @param int    $uid    云签到用户ID
 * @param string $tid    目标帖子ID
 * @param int    $pid    百度账号ID(用于取 BDUSS)
 * @param string $water  回复内容
 * @param int    $device 客户端类型(1=iPhone 2=Android 4=Windows)，默认 Android
 * @param string $kw     目标帖子所属贴吧名(不带"吧")
 * @param int    $fid    贴吧ID，0 表示自动解析
 * @return array  ['status'=>'1' 成功；其他=错误码/'err'，'msg'=>说明]
 */
function wmzz_post_send($uid, $tid, $pid, $water = '', $device = 2, $kw = '', $fid = 0)
{
	if (empty($uid) || empty($tid) || empty($pid)) {
		return array('status' => '-1', 'msg' => '缺少必要参数(uid/tid/pid)');
	}
	global $m;
	$ck = misc::getCookie($pid);
	if (empty($ck)) {
		return array('status' => '-1', 'msg' => '未找到该百度账号的登录凭据(BDUSS)，请到云签到重新登录贴吧账号');
	}
	if (empty($kw)) {
		return array('status' => '-1', 'msg' => '目标帖子缺少所属贴吧名，请在灌水设置里补全');
	}
	// 1. 解析贴吧ID：先从该用户的已关注列表取，取不到再请求一次
	if (empty($fid)) {
		$table = misc::getTable($uid);
		if (!empty($table)) {
			$q = $m->once_fetch_array("SELECT `fid` FROM `" . DB_PREFIX . $table . "` WHERE `uid` = '{$uid}' AND `tieba` = '" . addslashes($kw) . "' LIMIT 1");
			if (!empty($q['fid'])) {
				$fid = intval($q['fid']);
			}
		}
		if (empty($fid)) {
			$fid = intval(misc::getFid($kw));
		}
	}
	if (empty($fid)) {
		return array('status' => '-1', 'msg' => '无法获取贴吧ID，请检查填写的贴吧名是否正确');
	}
	// 2. 取 tbs(同时验证登录是否有效)
	$tbs = misc::getTbs($uid, $ck);
	if (empty($tbs)) {
		return array('status' => '-1', 'msg' => '获取tbs失败，百度账号可能已失效');
	}
	// 3. 构造请求参数并签名
	$x = array(
		'BDUSS'           => $ck,
		'_client_id'      => 'wappc_' . rand_int(10) . '_' . rand_int(3),
		'_client_type'    => in_array($device, array(1, 2, 4)) ? $device : 2,
		'_client_version' => '12.22.1.0',
		'_phone_imei'     => md5(rand_int(16)),
		'content'         => $water,
		'fid'             => $fid,
		'kw'              => $kw,
		'tbs'             => $tbs,
		'tid'             => $tid
	);
	ksort($x);
	$y = '';
	foreach ($x as $key => $value) {
		$y .= $key . '=' . $value;
	}
	$x['sign'] = strtoupper(md5($y . 'tiebaclient!!!'));
	$c = new wcurl('https://tieba.baidu.com/c/c/post/add', array(
		'User-Agent: bdtb for Android 6.5.8',
		'Content-Type: application/x-www-form-urlencoded'
	));
	@curl_setopt($c->conn, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
	@curl_setopt($c->conn, CURLOPT_TIMEOUT, 30);
	$c->addcookie('BDUSS=' . $ck);
	$return = json_decode($c->post($x), true);
	$c->close();
	if (empty($return) || !is_array($return)) {
		return array('status' => '-1', 'msg' => '发帖接口无响应，可能被贴吧风控拦截，请稍后再试');
	}
	$code = isset($return['error_code']) ? $return['error_code'] : '';
	if (isset($return['info']['need_vcode']) && $return['info']['need_vcode'] != '0' && ($code === '' || $code === '0' || $code === 0)) {
		return array('status' => '-1', 'msg' => '本次回复需要输入验证码，已自动跳过');
	}
	if ($code !== '' && $code !== '0' && $code !== 0) {
		$emsg = empty($return['error_msg']) ? '发送失败' : $return['error_msg'];
		return array('status' => is_numeric($code) ? $code : '-1', 'msg' => $emsg);
	}
	return array('status' => '1', 'msg' => '');
}

/**
 * 解析管理员后台的“发帖时间间隔”配置。
 * 新版为区间（sleep_min ~ sleep_max，秒）：每次自动回帖后，下一条会在区间内随机等待，每次都不同。
 * 兼容旧版：
 *   - 老字段 sleep 仅曾用于“同批多条之间固定停顿”，新逻辑改为一次一条，不再使用该字段；
 *   - 新字段不存在、或两边都填 0/留空时，回落到内置默认随机窗口 60~180 秒（约 1~3 分钟），防止连发触发风控。
 * @param mixed $set plugin_wmzz_post 反序列化后的配置
 * @return array{min:int,max:int,set:bool} min/max 为区间(秒)；set 表示管理员是否显式配置了区间
 */
function wmzz_interval_range($set)
{
	$r = array('min' => 60, 'max' => 180, 'set' => false);
	if (!is_array($set)) {
		$set = array();
	}
	if (isset($set['sleep_min']) || isset($set['sleep_max'])) {
		$mn = isset($set['sleep_min']) ? max(0, intval($set['sleep_min'])) : 0;
		$mx = isset($set['sleep_max']) ? max(0, intval($set['sleep_max'])) : 0;
		if ($mx == 0 && $mn > 0) {
			$mx = $mn; // 只填了最小值：视为固定间隔
		}
		if ($mx > 0) {
			if ($mx < $mn) {
				$t = $mx; $mx = $mn; $mn = $t; // 防止把最大/最小填反
			}
			$r['min'] = $mn;
			$r['max'] = $mx;
			$r['set'] = true;
		}
	}
	return $r;
}

/**
 * 把间隔配置渲染成一段人能看懂的文字。
 * @param array $range wmzz_interval_range() 的返回值
 * @return string
 */
function wmzz_range_text($range)
{
	if (!empty($range['set'])) {
		if ($range['min'] == $range['max']) {
			return '固定 ' . $range['min'] . ' 秒';
		}
		return '随机 ' . $range['min'] . '~' . $range['max'] . ' 秒';
	}
	return '默认随机 60~180 秒（约 1~3 分钟）';
}

/**
 * 读取全局“下次允许发包”时间戳（单位：秒）。
 * 用于把所有帖子的回帖串成同一条真人节奏：任何两个帖子的回帖之间也必须隔开随机间隔。
 * 返回 0 表示当前无约束（可立即发包）。存储于 options 表，跨分钟、跨进程持久。
 * @return int unix 秒
 */
function wmzz_gate_read()
{
	global $m;
	if (!is_object($m)) {
		return 0;
	}
	$q = $m->once_fetch_array("SELECT `value` FROM `" . DB_PREFIX . "options` WHERE `name` = 'wmzz_post_gate' LIMIT 1;");
	return (empty($q) || !isset($q['value'])) ? 0 : intval($q['value']);
}

/**
 * 写入全局“下次允许发包”时间戳。
 * @param int $ts unix 秒；0 表示清除限制
 */
function wmzz_gate_write($ts)
{
	global $m;
	if (!is_object($m)) {
		return;
	}
	$ts = max(0, intval($ts));
	@$m->query("INSERT INTO `" . DB_PREFIX . "options` (`name`, `value`) VALUES ('wmzz_post_gate', '{$ts}') ON DUPLICATE KEY UPDATE `value` = '{$ts}';");
}
