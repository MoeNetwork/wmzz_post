<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

function wmzz_post_send($uid, $tid, $pid, $water = 'StusGame Tieba Cloud Sign Plugin "wmzz_post"', $device = 4) {
	if (empty($uid) || empty($tid) || empty($pid)) {
		return array(
			'status' => '1',
			'msg'    => ''
		);
	}
	$ck = misc::GetCookie($pid);
	$xs = wmzz_post_gettie($tid,$ck);
	$x = array(
		'BDUSS' => $ck,
		'_client_id' => 'wappc_136' . rand_int(10) . '_' . rand_int(3),
		'_client_type' => $device,
		'_client_version' => '5.0.0',
		'_phone_imei' => md5(rand_int(16)),
		'anonymous' => '0',
		'content' => $water,
		'fid' => $xs['fid'],
		'kw' => $xs['word'],
		'net_type' => '3',
		'tbs' => $xs['tbs'],
		'tid' => $tid,
		'title' => '' 
	);
	$y = '';
	foreach ( $x as $key => $value ) {
		$y .= $key . '=' . $value;
	}
	$x['sign'] = strtoupper(md5($y.'tiebaclient!!!'));
	$c = new wcurl('http://c.tieba.baidu.com/c/c/post/add',array('Content-Type: application/x-www-form-urlencoded'));
	/* //Note:普通的
	$x = wmzz_post_gettie($tid,$ck);
	$c = new wcurl('http://tieba.baidu.com'.$x['__formurl']);
	unset($x['__formurl']);
	$x['co'] = $water;
	*/
	$c->addcookie('BDUSS='.$ck);
	$return = json_decode($c->post($x),true);
	$c->close();
	if (!empty($return['error_code']) && $return['error_code'] != '1') {
		return array(
			'status' => $return['error_code'],
			'msg'    => $return['error_msg']
		);
	} else {
		return array(
			'status' => '1',
			'msg'    => ''
		);
	}
}

function wmzz_post_gettie($tid, $ck) {
	$c = new wcurl('http://tieba.baidu.com/mo/m?kz='.$tid ,array('User-Agent: Chinese Fucking Phone'));
	$c->addcookie('BDUSS='.$ck);
	$t = $c->exec();
	preg_match('/<form action=\"(.*?)\" method=\"post\">/', $t , $formurl);
	preg_match('/<input type=\"hidden\" name=\"ti\" value=\"(.*?)\"\/>/', $t, $ti);
	preg_match('/<input type=\"hidden\" name=\"src\" value=\"(.*?)\"\/>/', $t, $src);
	preg_match('/<input type=\"hidden\" name=\"word\" value=\"(.*?)\"\/>/', $t, $word);
	preg_match('/<input type=\"hidden\" name=\"tbs\" value=\"(.*?)\"\/>/', $t, $tbs);
	preg_match('/<input type=\"hidden\" name=\"fid\" value=\"(.*?)\"\/>/', $t, $fid);
	preg_match('/<input type=\"hidden\" name=\"z\" value=\"(.*?)\"\/>/', $t, $z);
	preg_match('/<input type=\"hidden\" name=\"floor\" value=\"(.*?)\"\/>/', $t, $floor);
	return array(
		'__formurl' => $formurl[1],
		'co'        => '',
		'ti'        => $ti[1],
		'src'       => $src[1],
		'word'      => $word[1],
		'tbs'       => $tbs[1],
		'ifpost'    => '1',
		'ifposta'   => '0',
		'post_info' => '0',
		'tn'        => 'baiduWiseSubmit',
		'fid'       => $fid[1],
		'verify'    => '',
		'verify_2'  => '',
		'pinf'      => '1_2_0',
		'pic_info'  => '',
		'z'         => $z[1],
		'last'      => '0',
		'pn'        => '0',
		'r'         => '0',
		'see_lz'    => '0',
		'no_post_pic' => '0',
		'floor'     => $floor[1],
		'sub1'      => '回贴'
	);
}

function cron_wmzz_post() {
	global $m;
	$set = unserialize(option::get('plugin_wmzz_post'));
	$today = date("Y-m-d");
	//准备：扫描wmzz_post表中lastdo不是今天的，然后更新wmzz_post_data表的remain
	$sy = $m->query("SELECT * FROM `".DB_PREFIX."wmzz_post` WHERE `lastdo` != '{$today}';");
	while ($sx = $m->fetch_array($sy)) {
		$m->query('UPDATE `'.DB_NAME.'`.`'.DB_PREFIX.'wmzz_post_data` SET `remain` = \''.$sx['num'].'\' WHERE `uid` = '.$sx['uid']);
		$m->query('UPDATE `'.DB_NAME.'`.`'.DB_PREFIX.'wmzz_post` SET `lastdo` = \''.$today.'\' WHERE `uid` = '.$sx['uid']);
	}
	//开始：计划任务
	$count = $m->once_fetch_array("SELECT COUNT(*) AS `c` FROM `".DB_PREFIX."wmzz_post_data` WHERE `remain` > '0' LIMIT {$set['rem']};");
	if ($count['c'] == $set['rem']) {
		$y = rand_row(DB_PREFIX.'wmzz_post_data','id', $set['rem'] ,"`remain` > '0'");
	} else {
		$y = rand_row(DB_PREFIX.'wmzz_post_data','id', $count['c'] ,"`remain` > '0'");
	}
	//如果只有一条记录的兼容方案
	if (isset($y['url'])) {
		$y = array(0 => $y);
	}
	foreach ($y as $x) {
		if (!empty($x['pid']) && !empty($x['uid'])) {
			$u      = $m->once_fetch_array("SELECT * FROM `".DB_PREFIX."wmzz_post` WHERE `uid` = '{$x['uid']}'");
			$cont   = unserialize($u['cont']);
			$remain = $x['remain'] - 1 ;
			$res = wmzz_post_send($x['uid'] , $x['url'] , $x['pid'] , rand_array($cont) , $set['device']);
			$m->query('UPDATE `'.DB_NAME.'`.`'.DB_PREFIX.'wmzz_post_data` SET `remain` = \'' . $remain . '\',`status` = \''.$res['status'].'\',`msg` = \''.$res['msg'].'\' WHERE `url` = \''.$x['url'].'\' AND `uid` = '.$x['uid']);
			sleep($set['sleep']);
		}
	}
}
