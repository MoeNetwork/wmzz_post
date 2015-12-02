<?php
/*
Plugin Name: 贴吧帖子云灌水
Version: 2.5
Plugin URL: http://zhizhe8.net
Description: [精准回帖版] 实现对百度贴吧对指定帖子的自动灌水，此插件支持VIP功能
Author: 无名智者
Author Email: kenvix@vip.qq.com
Author URL: http://zhizhe8.net
For: V3.4+
*/
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

function wmzz_post_addaction_navi() {
	?>
	<li <?php if(isset($_GET['plugin']) && $_GET['plugin'] == 'wmzz_post') { echo 'class="active"'; } ?>><a href="index.php?plugin=wmzz_post"><span class="glyphicon glyphicon-cloud-upload"></span> 帖子云灌水</a></li>
	<?php
}

function wmzz_post_setting_navi() {
	?>
	<li><a href="index.php?mod=admin:setplug&plug=wmzz_post"><span class="glyphicon glyphicon-cloud-upload"></span> 帖子云灌水管理</a></li>
	<?php
}

addAction('navi_1','wmzz_post_addaction_navi');
addAction('navi_7','wmzz_post_addaction_navi');
addAction('navi_3','wmzz_post_setting_navi');