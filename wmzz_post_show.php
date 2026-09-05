<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }
require_once dirname(__FILE__) . '/wmzz_post_func.php';

$set = unserialize(option::get('plugin_wmzz_post'));
if (!is_array($set)) { $set = array(); }
global $i, $m;
wmzz_ensure_schema();

// ---------- 工具函数 ----------
//清洗贴吧名：去掉首尾空格与结尾的“吧”字
function wmzz_cleankw($kw)
{
	$kw = trim($kw);
	$kw = preg_replace('/吧\s*$/u', '', $kw);
	return trim($kw);
}

//从帖子地址中提取纯数字帖子ID，兼容各种粘贴来源
function wmzz_extract_tid($url)
{
	$u = trim($url);
	if (preg_match('#p/(\d+)#', $u, $mt)) {
		return $mt[1];
	}
	$u = preg_replace('/[?#].*$/', '', $u);
	$u = trim($u, '/');
	if (preg_match('/^\d+$/', $u)) {
		return $u;
	}
	return '';
}

//解析贴吧ID：优先从该用户已关注的贴吧表里取，取不到再请求
function wmzz_findfid($uid, $kw)
{
	global $m;
	$kwc = addslashes($kw);
	$table = @misc::getTable($uid);
	if (!empty($table)) {
		$q = $m->once_fetch_array("SELECT `fid` FROM `" . DB_PREFIX . $table . "` WHERE `uid` = '" . intval($uid) . "' AND `tieba` = '{$kwc}' LIMIT 1");
		if (!empty($q['fid'])) {
			return intval($q['fid']);
		}
	}
	return intval(@misc::getFid($kw));
}

//把最近状态渲染成 HTML（绿=正常 红=失败 灰=待执行）
function wmzz_status_html($x, $num)
{
	$st     = isset($x['status']) ? trim((string)$x['status']) : '';
	$msg    = isset($x['msg']) ? trim((string)$x['msg']) : '';
	$remain = intval($x['remain']);
	$ok     = ($st === '' || $st === '0' || $st === '1');
	if ($msg !== '') {
		return '<font color="' . ($ok ? 'green' : 'red') . '">' . htmlspecialchars($msg, ENT_QUOTES) . '</font>';
	}
	if ($ok) {
		if ($remain > 0) {
			return '<font color="#888">待执行</font>';
		}
		if ($num <= 0) {
			return '<font color="#888">已停用</font>';
		}
		if ($st === '1') {
			return '<font color="green">今日已完成</font>';
		}
		return '<font color="#888">—</font>';
	}
	return '<font color="red">#' . htmlspecialchars($st, ENT_QUOTES) . '</font>';
}

// ---------- 当前用户配置 ----------
$us = $m->once_fetch_array('SELECT * FROM `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post` WHERE `uid` = ' . UID . '');

// ---------- 删除目标 ----------
if (isset($_GET['del'])) {
	$id = intval($_GET['del']);
	$m->query("DELETE FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `uid` = '" . UID . "' AND `id` = '{$id}'");
	if (SYSTEM_PAGE == 'set') {
		ReDirect(SYSTEM_URL . 'index.php?plugin=wmzz_post&mod=set&ok');
	} else {
		ReDirect(SYSTEM_URL . 'index.php?plugin=wmzz_post');
	}
}

// ---------- 保存灌水设置 ----------
if (isset($_GET['save'])) {
	global $m;
	$tbss = isset($_POST['tieba']) ? $_POST['tieba'] : array();
	$kws  = isset($_POST['kw'])    ? $_POST['kw']    : array();
	$pid  = isset($_POST['pid'])   ? $_POST['pid']   : array();
	$num  = isset($_POST['num'])   ? intval($_POST['num']) : 0;
	$gapsec = 0;
	if (isset($_POST['gap'])) {
		$gapmin = floatval($_POST['gap']);
		$gapsec = intval(round(($gapmin > 0 ? $gapmin : 0) * 60)); // 用户输入为分钟，换算成秒存储
	}
	$conx = isset($_POST['content']) ? strip_tags($_POST['content']) : '';
	if (empty($conx)) {
		$wsc = isset($set['defcont']) ? $set['defcont'] : '';
	} else {
		$wsc = serialize(explode("\n", $conx));
	}
	// 管理员限额检查（VIP 不受限）
	$real_count = 0;
	foreach ($tbss as $key => $tbsx) {
		if (!empty(wmzz_extract_tid($tbsx)) && !empty($pid[$key])) {
			$real_count++;
		}
	}
	if (ISVIP == false && (!empty($set['lmax']) && $real_count > $set['lmax'])) {
		msg('设置无法保存，因为您的最大设置帖子数超过了管理员的设置');
	}
	if (ISVIP == false && (!empty($set['cmax']) && $num > $set['cmax'])) {
		msg('设置无法保存，因为您的最大单贴灌水帖子数超过了管理员的设置');
	}
	if (ISVIP == false && (!empty($set['max']) && $real_count * $num > $set['max'])) {
		msg('设置无法保存，因为您的总灌水量超过了管理员的设置');
	}
	// 保存每个灌水目标
	foreach ($tbss as $key => $tbsx) {
		$np = wmzz_extract_tid($tbsx);
		$kw = isset($kws[$key]) ? wmzz_cleankw($kws[$key]) : '';
		$pk = isset($pid[$key]) ? trim($pid[$key]) : '';
		if (empty($np)) {
			continue;
		}
		if (empty($kw)) {
			msg('设置无法保存：有帖子没填“所属贴吧”，请填上该帖子所在的贴吧名');
		}
		if (empty($pk)) {
			msg('设置无法保存：有帖子没选“对应 PID（回帖账号）”，请重新添加');
		}
		$fid = wmzz_findfid(UID, $kw);
		$ex = $m->once_fetch_array("SELECT `id` FROM `" . DB_NAME . "`.`" . DB_PREFIX . "wmzz_post_data` WHERE `uid` = '" . UID . "' AND `pid` = '{$pk}' AND `url` = '{$np}' LIMIT 1");
		if (empty($ex['id'])) {
			// 新增目标：当天直接给足当前 num 的额度（num=0 即停用）；只给额度、不触发发送
			$nmsg = ($num > 0) ? '待执行' : '数量为0，未启用';
			$m->query("INSERT INTO `" . DB_NAME . "`.`" . DB_PREFIX . "wmzz_post_data` (`uid`, `pid`, `url`, `kw`, `fid`, `status`, `msg`, `remain`) VALUES ('" . UID . "','{$pk}','{$np}','" . addslashes($kw) . "','{$fid}', 0, '" . addslashes($nmsg) . "', " . $num . ")");
		} else {
			$m->query("UPDATE `" . DB_NAME . "`.`" . DB_PREFIX . "wmzz_post_data` SET `url` = '{$np}', `kw` = '" . addslashes($kw) . "', `fid` = '{$fid}' WHERE `id` = '" . intval($ex['id']) . "'");
		}
	}

	// 保存每日数量 num，并按明确规则刷新“今日剩余额度”（只改额度，绝不自动触发回帖）
	$prevnum = (empty($us) || empty($us['num'])) ? 0 : intval($us['num']);
	$lastdo  = (empty($us) || empty($us['lastdo'])) ? '2000-01-01' : $us['lastdo'];
	$today   = date('Y-m-d');
	$m->query('INSERT INTO `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post` (`uid`, `cont`, `num`, `gap`) VALUES (' . UID . ', \'' . addslashes($wsc) . '\', \'' . $num . '\', \'' . $gapsec . '\') on duplicate key update `cont` = \'' . addslashes($wsc) . '\', `num` = \'' . $num . '\', `gap` = \'' . $gapsec . '\'');
	if ($num <= 0) {
		// 0 = 停用：清空今日剩余（已发过的不受影响），明天也不会自动补
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = 0, `try_ts` = 0, `fails` = 0 WHERE `uid` = ' . UID);
	} elseif ($lastdo !== $today) {
		// 今天还没初始化额度 → 立即给足新数量并标记今天已初始化（防 cron 重复补额）
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $num . ', `try_ts` = 0, `fails` = 0 WHERE `uid` = ' . UID);
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post` SET `lastdo` = \'' . $today . '\' WHERE `uid` = ' . UID);
	} elseif ($num > $prevnum) {
		// 今天已启动并调大数量 → 按差额补足；成功间隔中(status=1)的目标保持限速不变，
		// 仅在失败停摆时才解除退避重试（用户主动加额视为想再试）；只改额度、不触发发送
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = LEAST(`remain` + ' . ($num - $prevnum) . ', ' . $num . '), `fails` = 0, `try_ts` = IF(`status` = 1, `try_ts`, 0) WHERE `uid` = ' . UID);
	} elseif ($num < $prevnum) {
		// 调小数量 → 只把剩余砍到新上限以内（已发过的不可能撤回）
		$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = LEAST(`remain`, ' . $num . ') WHERE `uid` = ' . UID);
	}
	ReDirect(SYSTEM_URL . "index.php?plugin=wmzz_post&mod=set&ok");
	die;
}

// ---------- 手动测试回帖（与自动任务完全隔离：不扣 remain / 不改 num / 不改 lastdo / 不进队列） ----------
if (SYSTEM_PAGE == 'test') {
	@header('Content-Type: application/json; charset=utf-8');
	$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
	if ($id <= 0) {
		echo json_encode(array('ok' => false, 'msg' => '参数错误'));
		die;
	}
	$row = $m->once_fetch_array("SELECT * FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `id` = {$id} AND `uid` = " . UID);
	if (empty($row)) {
		echo json_encode(array('ok' => false, 'msg' => '记录不存在或无权操作'));
		die;
	}
	// 服务端防连点：同一目标 8 秒内只允许发起一次
	$now = time();
	@$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `test_at` = ' . $now . ' WHERE `id` = ' . $id . ' AND (`test_at` = 0 OR `test_at` < ' . ($now - 8) . ')');
	$ck = $m->once_fetch_array("SELECT `test_at` FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `id` = {$id}");
	if (isset($ck['test_at']) && $ck['test_at'] != $now) {
		echo json_encode(array('ok' => false, 'msg' => '操作太频繁，请等几秒再试'));
		die;
	}
	$tu = $m->once_fetch_array("SELECT * FROM `" . DB_PREFIX . "wmzz_post` WHERE `uid` = " . UID);
	$cont = (isset($tu['cont']) && $tu['cont'] !== '') ? @unserialize($tu['cont']) : array();
	if (empty($cont) || !is_array($cont) || empty(trim(implode('', $cont)))) {
		$cont = array('+3');
	}
	$device = (isset($set['device']) && in_array(intval($set['device']), array(1, 2, 3, 4))) ? intval($set['device']) : 4;
	$res = wmzz_post_send(UID, $row['url'], $row['pid'], rand_array($cont), $device, $row['kw'], intval($row['fid']));
	$code = (string)(isset($res['status']) ? $res['status'] : '-1');
	$err  = (string)(isset($res['msg']) ? $res['msg'] : '');
	if ($code == '1') {
		$txt  = '测试回帖成功';
		$scode = 1;
		$ok   = true;
	} else {
		$txt   = '测试回帖失败';
		if ($err !== '') {
			$txt .= '：' . $err;
		}
		$txt   .= '（错误码=' . $code . '）';
		$scode  = is_numeric($code) ? intval($code) : -1;
		$ok     = false;
	}
	// 写入“最近状态/错误信息”，方便直接看到真实结果；不影响 remain/num/lastdo
	$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `status` = ' . $scode . ', `msg` = \'' . addslashes($txt) . '\' WHERE `id` = ' . $id);
	$chName = isset($res['channel']) ? (string)$res['channel'] : '-';
	wmzz_log('wmzz_post manual-test uid=' . UID . ' tid=' . $row['url'] . ' ch=' . $chName . ' => ' . ($ok ? 'success' : 'fail: ' . $txt));
	echo json_encode(array('ok' => $ok, 'msg' => $txt, 'code' => $code, 'error' => $err));
	die;
}

// ---------- 重置今日额度（仅手动触发） ----------
if (SYSTEM_PAGE == 'reset') {
	$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
	if ($id <= 0) {
		msg('参数错误');
	}
	$num = (empty($us) || empty($us['num'])) ? 0 : intval($us['num']);
	$r2 = $m->once_fetch_array("SELECT `status` FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `id` = {$id} AND `uid` = " . UID);
	if (empty($r2)) {
		msg('记录不存在或无权操作');
	}
	// 上次是成功(status=1)且仍在随机间隔中的目标：保持限速，不在重置后立刻再发；仅失败/停摆才解锁
	$was_pace = (isset($r2['status']) && trim((string)$r2['status']) == '1');
	if ($num <= 0) {
		$setsql = '`remain` = 0, `try_ts` = 0, `fails` = 0, `status` = 0, `msg` = \'数量为0，未启用\'';
	} elseif ($was_pace) {
		$setsql = '`remain` = ' . $num . ', `fails` = 0, `status` = 0, `msg` = \'今日额度已重置，剩余 ' . $num . ' 次\'';
	} else {
		$setsql = '`remain` = ' . $num . ', `try_ts` = 0, `fails` = 0, `status` = 0, `msg` = \'今日额度已重置，剩余 ' . $num . ' 次\'';
	}
	$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET ' . $setsql . ' WHERE `id` = ' . $id . ' AND `uid` = ' . UID);
	ReDirect(SYSTEM_URL . 'index.php?plugin=wmzz_post&resetok=1');
}

// ---------- 手动设置“剩余灌水数”（仅手动触发，不超每日上限 num） ----------
if (SYSTEM_PAGE == 'setremain') {
	$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
	$v  = isset($_GET['remain']) ? intval($_GET['remain']) : -1;
	$num = (empty($us) || empty($us['num'])) ? 0 : intval($us['num']);
	if ($id <= 0 || $v < 0) {
		msg('参数错误');
	}
	$row2 = $m->once_fetch_array("SELECT id FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `id` = {$id} AND `uid` = " . UID);
	if (empty($row2)) {
		msg('记录不存在或无权操作');
	}
	if ($num <= 0) {
		msg('该账号“每天每个帖子的灌水数量”为 0（已停用）。请先在程序设置里设置数量，再来手动给额度');
	}
	$nv = min(max($v, 0), $num); // 不超出每日上限
	$m->query('UPDATE `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` SET `remain` = ' . $nv . ', `try_ts` = 0, `fails` = 0, `status` = 0, `msg` = \'手动设置剩余 ' . $nv . ' 次\' WHERE `id` = ' . $id . ' AND `uid` = ' . UID);
	wmzz_log('wmzz_post manual remain uid=' . UID . ' id=' . $id . ' => ' . $nv);
	ReDirect(SYSTEM_URL . 'index.php?plugin=wmzz_post&setok=1');
}

// ---------- 运行日志查看（只读显示 wmzz_post_cron.log 最近 400 行） ----------
if (SYSTEM_PAGE == 'log') {
	loadhead();
	echo '<h2>贴吧帖子云灌水 - 运行日志</h2>';
	echo '<ul class="nav nav-tabs">'
		. '<li><a href="index.php?plugin=wmzz_post">灌水日志</a></li>'
		. '<li class="active"><a href="index.php?plugin=wmzz_post&mod=log">运行日志</a></li>'
		. '<li><a href="index.php?plugin=wmzz_post&mod=set">程序设置</a></li>'
		. '</ul><br/>';
	echo '<div class="alert alert-info">自动任务与手动测试的运行记录（服务器插件目录 wmzz_post_cron.log）。每天跨天会自动补额并清除上一日的退避等待；success 一行的 ch= 表示走的通道（web=网页端，client:2/4…=客户端类型）。</div>';
	echo '<label style="font-weight:normal;margin:0 12px 6px 0;"><input type="checkbox" id="wmzzAutoScroll"> 自动滚动到最新（每 6 秒自动刷新并停在底部，选择会被记住）</label>';
	$logf = dirname(__FILE__) . '/wmzz_post_cron.log';
	$lines = array();
	if (is_readable($logf)) {
		$raw = @file_get_contents($logf);
		if ($raw !== false) {
			$lines = explode("\n", $raw);
			$lines = array_slice($lines, -400);
		}
	}
	echo '<pre id="wmzzLog" style="max-height:620px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:8px;font-size:12px;">'
		. htmlspecialchars(implode("\n", $lines), ENT_QUOTES) . '</pre>';
	echo '<br/><a class="btn btn-default" href="index.php?plugin=wmzz_post&mod=log">刷新</a>';
	echo '<script type="text/javascript">
(function(){
  var box = document.getElementById("wmzzAutoScroll");
  var pre = document.getElementById("wmzzLog");
  function on(){ try{ return localStorage.getItem("wmzz_autoscroll") === "1"; }catch(e){ return false; } }
  function save(v){ try{ localStorage.setItem("wmzz_autoscroll", v ? "1" : "0"); }catch(e){} }
  if (box) { box.checked = on(); }
  if (box && box.checked) {
    if (pre) { pre.scrollTop = pre.scrollHeight; }
    setInterval(function(){ location.reload(); }, 6000);
  }
  if (box) { box.addEventListener("change", function(){ save(box.checked); location.reload(); }); }
})();
</script>';
	loadfoot();
	die;
}

loadhead();
echo '<h2>贴吧帖子云灌水</h2>';
$usnum = (empty($us) || empty($us['num'])) ? 0 : intval($us['num']);
$uslastdo = (empty($us) || empty($us['lastdo'])) ? '2000-01-01' : $us['lastdo'];
$usgapsec = (empty($us) || empty($us['gap'])) ? 0 : intval($us['gap']);
$usgapmin = round($usgapsec / 60, 1);
// 管理员后台设定的随机间隔区间（未配置时给出默认 1~3 分钟）
$rngcfg = wmzz_interval_range($set);
$rngtxt = wmzz_range_text($rngcfg);

if (SYSTEM_PAGE == 'set') {
	$tbs = '';
	$content = '';
	$tbss = $m->query("SELECT * FROM `" . DB_PREFIX . "wmzz_post_data` WHERE `uid` = '" . UID . "';");
	while ($valux = $m->fetch_array($tbss)) {
		$tbs .= '<tr><td><input type="text" class="form-control" name="tieba[]" style="width:100%" value="https://tieba.baidu.com/p/' . htmlspecialchars($valux['url'], ENT_QUOTES) . '" readonly></td><td><input type="text" class="form-control" name="kw[]" style="width:100%" value="' . htmlspecialchars($valux['kw'], ENT_QUOTES) . '"></td><td><input type="text" name="pid[]" value="' . htmlspecialchars($valux['pid'], ENT_QUOTES) . '" class="form-control" readonly></td><td><a class="btn btn-default" title="删除" href="index.php?plugin=wmzz_post&mod=set&del=' . intval($valux['id']) . '"><b>X</b></a></td></tr>';
	}
	$tbs = trim($tbs, "\n");
	$val = (empty($us) || empty($us['cont'])) ? false : @unserialize($us['cont']);
	if (!empty($val)) {
		foreach ($val as $valu) {
			$content .= $valu . "\n";
		}
		$content = trim($content);
	}
	//生成“对应 PID(回帖账号)”下拉选项
	$pid_opts = '';
	if (!empty($i['user']['bduss'])) {
		foreach ($i['user']['bduss'] as $bid => $bv) {
			$bname = !empty($i['user']['baidu'][$bid]) ? $i['user']['baidu'][$bid] : $bid;
			$pid_opts .= '<option value="' . intval($bid) . '">' . htmlspecialchars($bname, ENT_QUOTES) . '（ID ' . intval($bid) . '）</option>';
		}
	}
	?>
	<ul class="nav nav-tabs">
	  <li><a href="index.php?plugin=wmzz_post">灌水日志</a></li>
	  <li><a href="index.php?plugin=wmzz_post&mod=log">运行日志</a></li>
	  <li class="active"><a href="#">程序设置</a></li>
	</ul>
	<?php
	if (isset($_GET['ok'])) {
		echo '<br/><div class="alert alert-success">设置保存成功</div>';
	}
	?>
	<script type="text/javascript">
	function addtb() {
		$('#tbs').append('<tr><td><input type="text" class="form-control" name="tieba[]"></td><td><input type="text" class="form-control" name="kw[]" placeholder="例：贝蒂"></td><td><select name="pid[]" class="form-control"><?php echo $pid_opts; ?></select></td><td></td></tr>');
	}
	</script>
	<form action="index.php?plugin=wmzz_post&save" method="post">
	<input type="button" style="float:right;" class="btn btn-info btn-lg" value="+ 增加" onclick="addtb()">
	<h3>灌水设置</h3>
	<ol>
	<li>每行一个目标。先在下面点“+ 增加”，然后依次填：<b>帖子地址</b>（如 https://tieba.baidu.com/p/1234567890）、<b>所属贴吧</b>（该帖子在哪个吧，可带可不带“吧”字）、<b>对应 PID（用哪个百度账号回帖）</b>。</li>
	<li>PID = 你站点里的百度账号ID，不是帖子的楼层号。已保存那行的 PID 只读，改目标请删掉该行重新添加；只改“所属贴吧”可直接编辑该格后点提交。</li>
	</ol>
	<table class="table table-striped">
		<thead>
			<tr><th style="width:45%">帖子地址</th><th style="width:20%">所属贴吧</th><th style="width:20%">对应 PID（回帖账号）</th><th></th></tr>
		</thead>
		<tbody id="tbs">
			<?php echo $tbs ?>
		</tbody>
	</table>
	<br/><h3>其他设置</h3>
	<table class="table table-striped">
	<thead>
		<tr>
			<th style="width:35%">参数</th>
			<th style="width:65%">值</th>
		</tr>
	</thead>
	<tbody>
	<tr>
		<td>设置灌水语句<br/><br/>留空将使用系统设定<br/><br/>每行一个，支持 HTML<br/>可以使用 &lt;br/&gt; 换行</td>
		<td>
			<textarea name="content" class="form-control" style="height:180px;"><?php echo $content ?></textarea>
		</td>
	</tr>
	<tr>
		<td>每天每个帖子的灌水数量（每日真实回帖上限）<br/>0 为不灌水<br/><br/><small>每天最多回帖数由本处决定；数量到账后按“两次回帖间隔”一条一条自动发送。</small></td>
		<td>
			<input type="number" min="0" step="1" name="num" class="form-control" value="<?php echo $usnum ?>">
			<br/><small>修改规则：今天还没开始 → 立即给足新数量；今天已开始且调大 → 只补差额；调小 → 砍到新上限内。改数量只改额度，不会自动触发回帖。</small>
		</td>
	</tr>
	<tr>
		<td>两次回帖间隔：固定的 X 分钟（可选底数）<br/>实际每次间隔 = X 分钟 + 管理员后台设置的随机区间<br/>随机部分每次都不同</td>
		<td>
			<input type="number" min="0" step="0.5" name="gap" class="form-control" value="<?php echo $usgapmin ?>">
			<br/><small>设 0 = 只按管理员后台的随机区间走（推荐）；设 2 = 每次都额外加 2 分钟。只影响自动回帖节奏，不影响“测试回帖”。</small>
		</td>
	</tr>
	</tbody>
	</table>
	<?php if (ISVIP == false && (!empty($set['max']) || !empty($set['cmax']) || !empty($set['lmax']))) {
		echo '注意：您';
		if (!empty($set['cmax']))
			echo '每天最大能为每个帖子灌 ' . $set['cmax'] . ' 次水，';
		if (!empty($set['lmax']))
			echo '最大能设置灌水 ' . $set['lmax'] . ' 个帖子，';
		if (!empty($set['max']))
			echo '能设置的最大灌水量为 ' . $set['max'] . ' 贴<br/>最大灌水量计算公式： 设置的帖子数 x 每个帖子的灌水数量 = 总灌水量';
		echo '<br/><br/>';
	} ?>
	<input type="submit" class="btn btn-primary" value="提交更改">
	</form>
	<?php } else { ?>
	<ul class="nav nav-tabs">
		  <li class="active"><a href="#">灌水日志</a></li>
		  <li><a href="index.php?plugin=wmzz_post&mod=log">运行日志</a></li>
		  <li><a href="index.php?plugin=wmzz_post&mod=set">程序设置</a></li>
		</ul>
	<?php
	if (isset($_GET['resetok'])) {
		echo '<br/><div class="alert alert-success">今日额度已重置，剩余数量见下表；额度到账后由自动任务在下一分钟内发送。</div>';
	}
	if (isset($_GET['setok'])) {
		echo '<br/><div class="alert alert-success">剩余灌水数已更新。注意：每天零点后会自动重置为“每天数量”，手动值不会保留到第二天。</div>';
	}
	$f = $m->query('SELECT * FROM `' . DB_NAME . '`.`' . DB_PREFIX . 'wmzz_post_data` WHERE `uid` = ' . UID . '');
	?>
	<br/>
	<div class="alert alert-info">
		当前已设置 <?php echo $m->num_rows($f); ?> 个灌水目标；每天每目标上限 <b><?php echo $usnum; ?></b> 次；两次回帖间隔 = 固定 <b><?php echo $usgapmin; ?></b> 分钟 + <b><?php echo $rngtxt; ?></b>
		<?php if ($uslastdo != '2000-01-01') echo '，今日额度初始化于 ' . $uslastdo; ?>
		（每天跨天自动补额并把“剩余”重置为“每天数量”；剩余可手动修改，仅当天有效；自动任务一次一条、由系统每分钟调度执行；失败不扣次数）
	</div>
	<table class="table table-striped">
		<thead>
			<tr>
				<th style="white-space:nowrap">PID</th>
				<th style="width:15%;white-space:nowrap">帖子ID</th>
				<th style="width:10%;white-space:nowrap">贴吧</th>
				<th style="width:8%;white-space:nowrap">每日数量</th>
				<th style="width:13%;white-space:nowrap">剩余灌水数</th>
				<th>最近状态/错误信息</th>
				<th style="white-space:nowrap">操作</th>
			</tr>
		</thead>
		<tbody>
		<?php
		while ($x = $m->fetch_array($f)) {
			$stathtml = wmzz_status_html($x, $usnum);
			$xid = intval($x['id']);
			echo '<tr>'
				. '<td>' . htmlspecialchars($x['pid'], ENT_QUOTES) . '</td>'
				. '<td><a href="https://tieba.baidu.com/p/' . htmlspecialchars($x['url'], ENT_QUOTES) . '" target="_blank">' . htmlspecialchars($x['url'], ENT_QUOTES) . '</a></td>'
				. '<td>' . htmlspecialchars($x['kw'], ENT_QUOTES) . '</td>'
				. '<td>' . $usnum . '</td>'
				. '<td><input type="number" min="0" max="' . max(0, $usnum) . '" step="1" class="form-control input-sm wmzz-remain-in" data-id="' . $xid . '" value="' . intval($x['remain']) . '" style="width:72px" title="直接改数字、离开输入框即自动保存（仅当天有效）"></td>'
				. '<td class="wmzz-status">' . $stathtml . '</td>'
				. '<td>'
				. '<button type="button" class="btn btn-primary btn-xs wmzz-test-btn" data-id="' . $xid . '">测试回帖</button> '
				. '<a class="btn btn-default btn-xs" href="index.php?plugin=wmzz_post&mod=reset&id=' . $xid . '">重置今日额度</a> '
				. '<a class="btn btn-danger btn-xs" href="index.php?plugin=wmzz_post&mod=set&del=' . $xid . '">删除</a>'
				. '</td></tr>';
		}
		?>
		</tbody>
	</table>
	<script type="text/javascript">
	$(function () {
		$('.wmzz-test-btn').on('click', function () {
			var $btn = $(this);
			var $tr  = $btn.closest('tr');
			if ($btn.data('busy')) { return; }
			$btn.data('busy', true).prop('disabled', true).text('测试中…');
			$.getJSON('index.php?plugin=wmzz_post&mod=test&id=' + $btn.data('id'))
				.done(function (r) {
					var color = (r && r.ok) ? 'green' : 'red';
					var msg   = (r && r.msg) ? r.msg : '请求失败，请重试';
					$tr.find('.wmzz-status').html('<font color="' + color + '">' + $('<div>').text(msg).html() + '</font>');
				})
				.fail(function () {
					$tr.find('.wmzz-status').html('<font color="red">请求失败，请检查网络后重试</font>');
				})
				.always(function () {
					$btn.data('busy', false).prop('disabled', false).text('测试回帖');
				});
		});
	});
	</script>
	<script type="text/javascript">
	$(function () {
		// “剩余灌水数”：直接改数字、离开输入框即自动保存（实时）
		$('.wmzz-remain-in').on('change', function () {
			var $inp = $(this);
			var v = parseInt($inp.val(), 10);
			if (isNaN(v) || v < 0) {
				$inp.val($inp.data('last') !== undefined ? $inp.data('last') : 0);
				return;
			}
			$inp.data('last', v);
			$.get('index.php?plugin=wmzz_post&mod=setremain&id=' + encodeURIComponent($inp.data('id')) + '&remain=' + v)
				.done(function () {
					$inp.css({ 'border-color': '#5cb85c', 'box-shadow': '0 0 0 0.2rem rgba(92,184,92,.25)' });
					setTimeout(function () { $inp.css({ 'border-color': '', 'box-shadow': '' }); }, 1200);
				})
				.fail(function () { $inp.css('border-color', '#d9534f'); });
		});
	});
	</script>
	<?php } ?>
	<br/><br/>注：只保留最后一次的状态；每天的数量在零点后由自动任务补足；“测试回帖”不消耗每日次数、不计入自动任务；剩余灌水数可直接改数字、离开输入框即自动保存（仅当天有效）。
	<br/><br/>运行记录可在上方“运行日志”页查看（自动任务 + 手动测试都会写入）。
	<br/><br/>贴吧云灌水 [ 精准回帖版 ] V2.5-fix | 百度贴吧云签到
	<?php loadfoot(); ?>
