<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

function callback_active() {}

function callback_init() {
	global $m;
	$m->query("CREATE TABLE IF NOT EXISTS `".DB_PREFIX."wmzz_post` (
`id`  int(255) NOT NULL AUTO_INCREMENT ,
`uid`  int(255) NOT NULL ,
`cont`  text CHARACTER SET utf8 COLLATE utf8_general_ci NULL ,
`num`  int(255) NULL DEFAULT NULL ,
`lastdo`  date NOT NULL DEFAULT '0000-00-00' ,
PRIMARY KEY (`id`, `uid`),
UNIQUE INDEX `uid` (`uid`) USING BTREE 
)
ENGINE=MyISAM
DEFAULT CHARACTER SET=utf8 COLLATE=utf8_general_ci
CHECKSUM=0
ROW_FORMAT=DYNAMIC
DELAY_KEY_WRITE=0
;");
	$m->query("CREATE TABLE IF NOT EXISTS `".DB_PREFIX."wmzz_post_data` (
`id`  int(255) NOT NULL AUTO_INCREMENT ,
`uid`  int(255) NOT NULL DEFAULT 0 ,
`pid`  int(255) NOT NULL DEFAULT 0 ,
`url`  varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL ,
`status`  int(10) NOT NULL DEFAULT 0 ,
`msg`  varchar(400) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL ,
`remain`  int(255) NOT NULL DEFAULT 0 ,
PRIMARY KEY (`id`)
)
ENGINE=MyISAM
DEFAULT CHARACTER SET=utf8 COLLATE=utf8_general_ci
CHECKSUM=0
ROW_FORMAT=DYNAMIC
DELAY_KEY_WRITE=0
;");
	cron::set('wmzz_post','plugins/wmzz_post/wmzz_post_cron.php',0,0,0);
	option::add('plugin_wmzz_post','a:7:{s:5:"sleep";s:1:"0";s:4:"lmax";s:1:"0";s:4:"cmax";s:1:"0";s:3:"max";s:1:"0";s:3:"rem";s:1:"5";s:7:"defcont";s:72:"欢迎使用 StusGame 贴吧云灌水
这是一个默认的灌水内容";s:6:"device";s:1:"4";}');
}

function callback_inactive() {
	cron::del('wmzz_post');
}

function callback_remove() {
	global $m;
	option::del('plugin_wmzz_post');
	$m->query("DROP TABLE IF EXISTS `".DB_PREFIX."wmzz_post`");
	$m->query("DROP TABLE IF EXISTS `".DB_PREFIX."wmzz_post_data`");
}