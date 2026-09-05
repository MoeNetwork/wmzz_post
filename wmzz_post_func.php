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
 * 记录一次发帖接口的完整原始响应（排查 3250026 / 224011 等错误用），自动轮转。
 * 只记录响应，不记录请求内容（不含任何 Cookie/密码等敏感字段）。
 * @param int    $http HTTP 状态码
 * @param string $raw  接口返回的原始文本
 */
function wmzz_resp_log($http, $raw)
{
	$file = dirname(__FILE__) . '/wmzz_post_resp.log';
	$head = '[' . date('Y-m-d H:i:s') . '] http=' . intval($http);
	$text = $head . PHP_EOL . $raw . PHP_EOL;
	if (file_exists($file) && @filesize($file) > 512 * 1024) {
		@file_put_contents($file, $text, LOCK_EX); // 超限后重置文件
		return;
	}
	@file_put_contents($file, $text, FILE_APPEND | LOCK_EX);
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
	// 升级本插件默认文本列为 utf8mb4：支持 emoji 表情存储/发送（幂等；utf8mb4 是 utf8 超集，兼容老数据）
	@$m->query("ALTER TABLE {$tu} MODIFY `cont` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
	@$m->query("ALTER TABLE {$t} MODIFY `kw` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL");
	@$m->query("ALTER TABLE {$t} MODIFY `msg` varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL");
}

/**
 * 对指定帖子发送一条回复（多通道自动切换：一端不行就换另一端）。
 * 通道顺序（首个成功即停，一次最多成功一条）：
 *   0. 网页(电脑/浏览器)端 f/commit/post/add —— 需要 STOKEN；账号在客户端接口触发风控(AI 验证码 224011)时，
 *      实测网页端同一账号无需验证码即可成功。
 *   1. 客户端接口 c/c/post/add，_client_type 依次轮询：配置的首选 device → 4(Windows/Wap) → 2(Android)
 *      → 1(iPhone) → 3(WindowsPhone)。
 *      说明：type 3/4 并非“永久废弃”——正常账号状态可正常发帖；仅在被风控后贴吧对旧类型返回
 *      “请升级到最新版本”(3250026)，此时换 type2/网页端通常可发，因此全部端都纳入自动切换。
 * 注意：旧的“每天每帖”/“每小时上限”/退避等仍由调度（cron）负责，本函数只负责把一条发出去。
 * @param int    $uid    云签到用户ID
 * @param string $tid    目标帖子ID
 * @param int    $pid    百度账号ID(用于取 BDUSS/STOKEN)
 * @param string $water  回复内容
 * @param int    $device 首选客户端类型(1=iPhone 2=Android 3=WindowsPhone 4=Windows/Wap)，之后自动轮询其余类型
 * @param string $kw     目标帖子所属贴吧名(不带"吧")
 * @param int    $fid    贴吧ID，0 表示自动解析
 * @return array  ['status'=>'1' 成功；其他=错误码/'err'，'msg'=>说明, 'refused'=>bool, 'channel'=>string, 'tried'=>string]
 */
function wmzz_post_send($uid, $tid, $pid, $water = '', $device = 4, $kw = '', $fid = 0)
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
	// 3. 组装通道顺序，逐个尝试，首个成功即停
	$cs = misc::getCookie($pid, true);
	$stoken = (is_array($cs) && !empty($cs['stoken'])) ? (string)$cs['stoken'] : '';

	$order = array();
	if ($stoken !== '') {
		$order[] = 'web';
	}
	$dev = in_array(intval($device), array(1, 2, 3, 4)) ? intval($device) : 4;
	$types = array();
	foreach (array($dev, 4, 2, 1, 3) as $t) {
		if (!in_array($t, $types, true)) {
			$types[] = $t; // 去重且保持“首选在前”
		}
	}
	foreach ($types as $t) {
		$order[] = 'client:' . $t;
	}

	$last    = null;
	$refused = false;
	$tried   = array();
	foreach ($order as $ch) {
		if ($ch === 'web') {
			$r = wmzz_post_send_web($ck, $stoken, $kw, $fid, $tbs, $tid, $water);
		} else {
			$r = wmzz_post_send_client($ck, $fid, $tbs, $kw, $tid, $water, (int)substr($ch, 7));
		}
		$r['channel'] = $ch;
		$code = isset($r['status']) ? (string)$r['status'] : '-1';
		$tried[] = $ch . '=>' . ($code === '1' ? 'ok' : $code); // 记录每次通道尝试，便于日志/页面查看
		$last = $r;
		if (!empty($r['refused'])) {
			$refused = true; // 只要任一端被接口明确拒绝，整次就视为“被拒”
		}
		if ($code === '1') {
			$r['tried'] = implode(' | ', $tried); // 任一端成功即成功
			return $r;
		}
	}
	$triedStr = implode(' | ', $tried);
	if (!is_array($last)) {
		return array('status' => '-1', 'msg' => '没有可用发帖通道', 'refused' => $refused, 'tried' => $triedStr);
	}
	$last['refused'] = $refused;
	$last['tried']   = $triedStr;
	return $last;
}

/**
 * 手机/PC 客户端接口发一条回复：https://tieba.baidu.com/c/c/post/add（参数经 ksort + tiebaclient!!! 签名）。
 * @param string $bduss 百度账号 BDUSS
 * @param string $fid   贴吧 ID
 * @param string $tbs   发帖签名 tbs
 * @param string $kw    目标帖子所属贴吧名
 * @param string $tid   目标帖子 ID
 * @param string $water 回复内容
 * @param int    $type  _client_type：1=iPhone 2=Android 3=WindowsPhone 4=Windows/Wap
 * @return array ['status'=>'1' 成功；其他=错误码/'err'，'msg'=>说明, 'refused'=>bool]
 */
function wmzz_post_send_client($bduss, $fid, $tbs, $kw, $tid, $water, $type)
{
	$type = in_array($type, array(1, 2, 3, 4)) ? $type : 2;
	$x = array(
		'BDUSS'           => $bduss,
		'_client_id'      => 'wappc_' . rand_int(10) . '_' . rand_int(3),
		'_client_type'    => $type,
		'_client_version' => '12.95.1.0',
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
	$c->addcookie('BDUSS=' . $bduss);
	$rawBody = $c->post($x);
	$http    = @curl_getinfo($c->conn, CURLINFO_HTTP_CODE);
	$c->close();
	wmzz_resp_log($http, (string)$rawBody); // 排查用：每次发帖的完整原始响应都落盘
	$return = json_decode((string)$rawBody, true);
	if (empty($return) || !is_array($return)) {
		return array('status' => '-1', 'msg' => '发帖接口无响应，可能被贴吧风控拦截，请稍后再试', 'refused' => true, 'err_type' => 'risk');
	}
	$code = isset($return['error_code']) ? $return['error_code'] : '';
	if (isset($return['info']['need_vcode']) && $return['info']['need_vcode'] != '0'
		&& ($code === '' || $code === '0' || $code === 0)) {
		return array('status' => '-1', 'msg' => '本次回复需要验证码，已自动跳过', 'refused' => true, 'err_type' => 'risk');
	}
	if ($code !== '' && $code !== '0' && $code !== 0) {
		$emsg = empty($return['error_msg']) ? '发送失败' : $return['error_msg'];
		return array('status' => is_numeric($code) ? $code : '-1', 'msg' => $emsg, 'refused' => true, 'err_type' => wmzz_err_type($code, $emsg));
	}
	return array('status' => '1', 'msg' => '', 'refused' => false, 'err_type' => '');
}

/**
 * 网页(电脑/浏览器)通道发一条回复：https://tieba.baidu.com/f/commit/post/add
 * 说明：贴吧移动客户端接口在账号触发风控后要求 AI 验证码(224011)，网页端实测同一账号状态无需验证码
 *       (err_code=0 / vcode.need_vcode=0)。网页通道需要 BDUSS + STOKEN + tbs。
 * @param string $bduss  百度账号 BDUSS
 * @param string $stoken 百度账号 STOKEN（网页登录态必需）
 * @param string $kw     目标帖子所属贴吧名
 * @param string $fid    贴吧 ID
 * @param string $tbs    发帖签名 tbs
 * @param string $tid    目标帖子 ID
 * @param string $water  回复内容
 * @return array ['status'=>'1' 成功；其他=错误码/'err'，'msg'=>说明, 'refused'=>bool]
 */
function wmzz_post_send_web($bduss, $stoken, $kw, $fid, $tbs, $tid, $water)
{
	if ($bduss === '' || $stoken === '') {
		return array('status' => '-1', 'msg' => '缺少 BDUSS/STOKEN，无法走网页发帖', 'refused' => false, 'err_type' => 'other');
	}
	$post = 'ie=utf-8&kw=' . rawurlencode($kw)
		. '&fid=' . rawurlencode($fid)
		. '&tid=' . rawurlencode($tid)
		. '&tbs=' . rawurlencode($tbs)
		. '&content=' . rawurlencode($water)
		. '&rich_text=1&floor_num=1&basilisk=1&__type__=reply';
	$headers = array(
		'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
		'Content-Type: application/x-www-form-urlencoded',
		'Referer: https://tieba.baidu.com/p/' . $tid,
		'X-Requested-With: XMLHttpRequest'
	);
	$ch = curl_init('https://tieba.baidu.com/f/commit/post/add');
	@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($ch, CURLOPT_POST, true);
	@curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
	@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	@curl_setopt($ch, CURLOPT_COOKIE, 'BDUSS=' . $bduss . '; STOKEN=' . $stoken);
	@curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	$raw  = curl_exec($ch);
	$http = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	wmzz_resp_log($http, (string)$raw); // 排查用：每次发帖的完整原始响应都落盘
	$j = json_decode((string)$raw, true);
	if (!is_array($j)) {
		return array('status' => '-1', 'msg' => '网页发帖接口无响应，可能被拦截', 'refused' => true, 'err_type' => 'risk');
	}
	$data = (isset($j['data']) && is_array($j['data'])) ? $j['data'] : array();
	// 未登录 / STOKEN 失效：这属于登录态问题而非风控拒绝，交调度按“单目标稍后重试”处理
	if (isset($data['is_login']) && $data['is_login'] != 1 && $data['is_login'] != '1') {
		return array('status' => '-1', 'msg' => '网页发帖未登录（STOKEN 可能失效），请到云签到重新保存该贴吧账号', 'refused' => false, 'err_type' => 'other');
	}
	// 网页端偶发也要求验证码
	if (isset($data['vcode']) && is_array($data['vcode']) && isset($data['vcode']['need_vcode'])
		&& $data['vcode']['need_vcode'] != 0 && $data['vcode']['need_vcode'] != '0') {
		return array('status' => '-1', 'msg' => '网页发帖需要验证码，请稍后再试', 'refused' => true, 'err_type' => 'risk');
	}
	$no    = isset($j['no']) ? $j['no'] : null;
	$ecode = isset($j['err_code']) ? $j['err_code'] : null;
	if (($no === 0 || $no === '0') && ($ecode === 0 || $ecode === '0')) {
		return array('status' => '1', 'msg' => '', 'refused' => false, 'err_type' => '');
	}
	// 失败：尽量给出可读原因（网页端明确拒绝也计入“被拒”）
	$emsg = '';
	if (!empty($j['error']) && is_string($j['error'])) {
		$emsg = $j['error'];
	} elseif (!empty($data['autoMsg'])) {
		$emsg = $data['autoMsg'];
	} elseif (!empty($data['error']) && is_string($data['error'])) {
		$emsg = $data['error'];
	}
	if ($emsg === '') {
		$emsg = '网页发帖失败';
	}
	return array('status' => is_numeric($no) ? $no : '-1', 'msg' => $emsg, 'refused' => true, 'err_type' => wmzz_err_type($no, $emsg));
}

/**
 * 解析管理员后台的“发帖时间间隔”配置。
 * 新版为区间（sleep_min ~ sleep_max，秒）：每次成功回帖后，下一条会在区间内随机等待，每次都不同。
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
 * 读取一个全局开关值（options 表）。返回 null 表示不存在。
 * @param string $name 设置名
 * @return string|null
 */
function wmzz_opt_read($name)
{
	global $m;
	if (!is_object($m)) {
		return null;
	}
	$q = $m->once_fetch_array("SELECT `value` FROM `" . DB_PREFIX . "options` WHERE `name` = '" . addslashes($name) . "' LIMIT 1;");
	return (empty($q) || !isset($q['value'])) ? null : $q['value'];
}

/**
 * 写入一个全局开关值（options 表，不存在则自动插入）。
 * @param string $name  设置名
 * @param mixed  $value 值
 */
function wmzz_opt_write($name, $value)
{
	global $m;
	if (!is_object($m)) {
		return;
	}
	$name  = addslashes($name);
	$value = addslashes((string)$value);
	@$m->query("INSERT INTO `" . DB_PREFIX . "options` (`name`, `value`) VALUES ('{$name}', '{$value}') ON DUPLICATE KEY UPDATE `value` = '{$value}';");
}

/**
 * 读取全局“下次允许发包”时间戳（单位：秒）。
 * 0 表示当前无约束（可立即发包）。任何帖子的两条回帖之间都必须隔开。
 * @return int unix 秒
 */
function wmzz_gate_read()
{
	return intval(wmzz_opt_read('wmzz_post_gate'));
}

/**
 * 写入全局“下次允许发包”时间戳。
 * @param int $ts unix 秒；0 表示清除限制
 */
function wmzz_gate_write($ts)
{
	wmzz_opt_write('wmzz_post_gate', max(0, intval($ts)));
}

/**
 * 读取全局“连续被拒次数”（各端都被接口拒绝才累计；成功一条即清零）。
 * @return int
 */
function wmzz_fails_read()
{
	return intval(wmzz_opt_read('wmzz_post_fails'));
}

/**
 * 写入全局“连续被拒次数”。
 * @param int $n 次数
 */
function wmzz_fails_write($n)
{
	wmzz_opt_write('wmzz_post_fails', max(0, intval($n)));
}

/**
 * 读取“本小时已成功条数”计数。值为 "YmdH:count"，跨小时自动归零。
 * @return array{key:string,count:int}
 */
function wmzz_hour_read()
{
	$v = wmzz_opt_read('wmzz_post_hour');
	if ($v === null || $v === '') {
		return array('key' => '', 'count' => 0);
	}
	$p = explode(':', $v, 2);
	return array('key' => isset($p[0]) ? $p[0] : '', 'count' => isset($p[1]) ? intval($p[1]) : 0);
}

/**
 * 写入“本小时已成功条数”计数。
 * @param string $key   YmdH
 * @param int    $count 本小时成功条数
 */
function wmzz_hour_write($key, $count)
{
	wmzz_opt_write('wmzz_post_hour', $key . ':' . max(0, intval($count)));
}

/**
 * 被拒后的递增随机退避（按用户自定义区间，分钟）。
 * 将区间 [min,max] 等分为三段，随连续失败次数 n 逐段加大取值（都随机、不完全重复）：
 *   - 第 1 次：取下限段（偏小），如 20~150 → 20~63 分钟区间随机；
 *   - 第 2 次：取中段，如 63~107 分钟随机；
 *   - 第 3 次及以上：取上限段（偏大），如 107~150 分钟随机，之后保持在大段内随机。
 * @param int $n     连续被拒次数
 * @param int $minMin 区间下限（分钟）
 * @param int $maxMin 区间上限（分钟）
 * @return int 秒
 */
function wmzz_backoff_sec($n, $minMin, $maxMin)
{
	$lo = max(0, intval($minMin));
	$hi = max(0, intval($maxMin));
	if ($lo <= 0 || $hi <= 0) {
		$lo = 20; $hi = 150; // 未配置时的默认区间（分钟）
	}
	if ($hi < $lo) {
		$t = $hi; $hi = $lo; $lo = $t;
	}
	$n    = max(1, intval($n));
	$seg  = max(1, intdiv($hi - $lo, 3));
	if ($n == 1) {
		$a = $lo;                 $b = min($hi, $lo + $seg);
	} elseif ($n == 2) {
		$a = min($hi, $lo + $seg); $b = min($hi, $lo + 2 * $seg);
	} else {
		$a = min($hi, $lo + 2 * $seg); $b = $hi;
	}
	if ($b < $a) { $b = $a; }
	return mt_rand($a, $b) * 60;
}

/**
 * 判断发帖失败的错误类型（用于区分“目标/账号级问题”与“风控”）。
 * @param string $code 错误码
 * @param string $msg  错误说明
 * @return string 'notfound' 帖子不存在 | 'muted' 被禁言 | 'risk' 风控/验证码 | 'other' 其他
 */
function wmzz_err_type($code, $msg)
{
	$code = (string)$code;
	$msg  = (string)$msg;
	if ($code === '230273' || strpos($msg, '帖子不存在') !== false || strpos($msg, '不存在') !== false) {
		return 'notfound';
	}
	if (strpos($msg, '禁言') !== false) {
		return 'muted';
	}
	return 'risk';
}
