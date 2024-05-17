<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 
$set = unserialize(option::get('plugin_wmzz_post'));
global $i,$m;
$us=$m->once_fetch_array('SELECT * FROM  `'.DB_NAME.'`.`'.DB_PREFIX.'wmzz_post` WHERE  `uid` = '.UID.'');
if (isset($_GET['del'])) {
	$id = intval($_GET['del']);
	$m->query("DELETE FROM `".DB_PREFIX."wmzz_post_data` WHERE `uid` = '".UID."' AND `id` = '{$id}'");
	ReDirect(SYSTEM_URL . 'index.php?plugin=wmzz_post&mod=set&ok');
}
if (isset($_GET['save'])) {
	global $m;
	$tbss = isset($_POST['tieba'])   ? $_POST['tieba']                : array();
	$pid  = isset($_POST['pid'])     ? $_POST['pid']                  : array();
	$num  = isset($_POST['num'])     ? intval($_POST['num'])          : '0';
	$rcid = isset($_POST['rcid'])    ? $_POST['rcid']                 : array();
	$rcidk = 0;
	$conx = isset($_POST['content']) ? addslashes(strip_tags($_POST['content'])) : '';
	if (empty($conx)) {
		$wsc  = $set['defcont'];
	} else {
		$wsc  = serialize(explode("\n", $conx));
	}
	if (ISVIP == false && (!empty($set['lmax']) && count($tbss) > $set['lmax'])) {
		msg('设置无法保存，因为您的最大设置帖子数超过了管理员的设置');
	}
	if (ISVIP == false && (!empty($set['cmax']) && $num > $set['cmax'])) {
		msg('设置无法保存，因为您的最大单贴灌水帖子数超过了管理员的设置');
	}
	if (ISVIP == false && (!empty($set['max']) && count($tbss) * $num > $set['max'])) {
		msg('设置无法保存，因为您的总灌水量超过了管理员的设置');
	}
	foreach ($tbss as $key => $tbsx) {
		if (!empty($tbsx) && !empty($pid[$key])) {
			$np  = str_ireplace('http://tieba.baidu.com/p/', '', $tbsx);
			$np  = str_ireplace('https://tieba.baidu.com/p/', '', $np);
			$tes = $m->once_fetch_array("SELECT count(*) AS `c` FROM `".DB_NAME."`.`".DB_PREFIX."wmzz_post_data` WHERE `uid` = '".UID."' AND `pid` = '{$pid[$key]}' AND `url` = '{$np}'");
			if($tes['c'] <= 0) {
				$m->query("INSERT INTO `".DB_NAME."`.`".DB_PREFIX."wmzz_post_data` ( `id`,`uid`,`pid`,`url` ) VALUES ( NULL,'".UID."','{$pid[$key]}','{$np}' );");
			} else {
				$m->query("UPDATE `".DB_NAME."`.`".DB_PREFIX."wmzz_post_data` SET `url` = '{$np}', `pid` = '{$pid[$key]}' WHERE `id` = '{$rcid[$rcidk]}';");
				$rcidk = $rcidk + 1;
			}
		}
	}
	$m->query('INSERT INTO `'.DB_NAME.'`.`'.DB_PREFIX.'wmzz_post` (`uid`, `cont`, `num`) VALUES ('.UID.', \''. $wsc .'\', \''.$num.'\') on duplicate key update `cont` = \''. $wsc .'\', `num` = \''.$num.'\'');
	ReDirect(SYSTEM_URL."index.php?plugin=wmzz_post&mod=set&ok");
	die;
}
loadhead();
	echo '<h2>贴吧帖子云灌水</h2>';
	if(SYSTEM_PAGE == 'set') {
	$tbs = '';
	$content = '';
	$tbss = $m->query("SELECT * FROM `".DB_PREFIX."wmzz_post_data` WHERE `uid` = '".UID."';");
	while ($valux = $m->fetch_array($tbss)) {
		$tbs .= '<tr><td><input type="text" class="form-control" name="tieba[]" style="width:100%" value="https://tieba.baidu.com/p/'.$valux['url'].'" readonly></td><td><input type="text" name="pid[]" value="'.$valux['pid'].'" class="form-control" readonly></td><td><a class="btn btn-default" title="删除" href="index.php?plugin=wmzz_post&mod=set&del='.$valux['id'].'"><b>X</b></a></td></tr>';
	}
	$tbs = trim($tbs,"\n");
	$val = unserialize($us['cont']);
	if (!empty($val)) {
		foreach ($val as $valu) {
			$content .= $valu."\n";
		}
		$content = trim($content);
	}
	?>
	<ul class="nav nav-tabs">
	  <li><a href="index.php?plugin=wmzz_post">灌水日志</a></li>
	  <li class="active"><a href="#">程序设置</a></li>
	</ul>
	<?php
	if (isset($_GET['ok'])) {
		echo '<br/><div class="alert alert-success">设置保存成功</div>';
	}
	?>
	<script type="text/javascript">
	function addtb() {
		$('#tbs').append('<tr><td><input type="text" class="form-control" name="tieba[]" colspan="2"></td><td><select name="pid[]" class="form-control"><?php
foreach ($i['user']['bduss'] as $keyyy => $valueee) {
	echo '<option value="'.$keyyy.'">'.$keyyy.'</option>';
} ?></select></td></tr>');
	}
	</script>
	<form action="index.php?plugin=wmzz_post&save" method="post">
	<input type="button" style="float:right;" class="btn btn-info btn-lg" value="+ 增加" onclick="addtb()">
	<h3>灌水设置</h3>
	输入需要灌水的帖子的地址。按增加按钮添加新灌水地址。留空为不灌水。贴吧名称后面不要带 吧
	<table class="table table-striped">
		<thead>
			<th style="width:70%">地址</th>
			<th style="width:30%">对应 PID</th>
			<th></th>
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
			<textarea name="content" class="form-control" style="height:400px;"><?php echo $content ?></textarea>
		</td>
	</tr>
	<tr>
		<td>每天每个帖子的灌水数量<br/>0 为不灌水</td>
		<td>
			<input type="number" min="0" step="1" name="num" class="form-control" value="<?php echo $us['num'] ?>">
		</td>
 	</tr>
	</tbody>
	</table>
<?php if (ISVIP == false && (!empty($set['max']) || !empty($set['cmax']) || !empty($set['lmax']))) {
	echo '注意：您';
	if (!empty($set['cmax'])) 
		echo '每天最大能为每个帖子灌 '.$set['cmax'].' 次水，';
	if (!empty($set['lmax'])) 
		echo '最大能设置灌水 '.$set['lmax'].' 个帖子，';
	if (!empty($set['max']))
		echo '能设置的最大灌水量为 '. $set['max'] . ' 贴<br/>最大灌水量计算公式： 设置的帖子数 x 每个帖子的灌水数量 = 总灌水量'; 
	echo '<br/><br/>';
} ?>
<input type="submit" class="btn btn-primary" value="提交更改">
</form>
<?php } else { ?>
<ul class="nav nav-tabs">
	  <li class="active"><a href="#">灌水日志</a></li>
	  <li><a href="index.php?plugin=wmzz_post&mod=set">程序设置</a></li>
	</ul>
<?php
$f = $m->query('SELECT * FROM  `'.DB_NAME.'`.`'.DB_PREFIX.'wmzz_post_data` WHERE  `uid` = '.UID.'');
?>
<br/>
<div class="alert alert-info">
	当前已设置 <?php echo $m->num_rows($f); ?> 个要灌水的帖子
	<?php if($us['lastdo'] != '2000-01-01') echo '，最后一次灌水在 '.$us['lastdo']; ?>，PID 即为 百度账号ID
</div>
<table class="table table-striped">
	<thead>
		<tr>
			<th>PID</th>
			<th style="width:20%">帖子ID</th>
			<th style="width:20%">剩余灌水数</th>
			<th style="width:40%">最近状态/错误信息</th>
		</tr>
	</thead>
	<tbody>
	<?php
	while($x = $m->fetch_array($f)) {
		if ($x['status'] == '0' || $x['status'] == '1' || empty($x['msg'])) {
			$stat = '<font color="green">成功</font>';
			$err = '，无错误';
		} else {
			$stat = '<font color="red">失败</font>';
			$err = '，#'.$x['status'].' : '.$x['msg'];
		}
		echo '<tr><td>'.$x['pid'].'</td><td><a href="https://tieba.baidu.com/p/'.$x['url'].'" target="_blank">'.$x['url'].'</td><td>'.$x['remain'].' 贴</td><td>'.$stat.$err.'</td></tr>';
	}
	?>
	</tbody>
</table>
<?php } ?>
<br/><br/>注：只保留最后一次的灌水记录
<br/><br/>贴吧云灌水 [ 精准回帖版 ] V2.1 By <a href="http://zhizhe8.net" target="_blank">无名智者</a> | 百度贴吧云签到 V2.0 By <a href="http://zhizhe8.net" target="_blank">无名智者</a>
<?php loadfoot(); ?>
