<?php
define('IN_PHPBB', true);
define('ROOT_PATH', __DIR__ . "/../forum");

if (!defined('IN_PHPBB') || !defined('ROOT_PATH')) {
exit();
}

$phpEx = "php";
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : ROOT_PATH . '/';
include($phpbb_root_path . 'common.' . $phpEx);

$user->session_begin();
$auth->acl($user->data);
$request->enable_super_globals();

session_start();