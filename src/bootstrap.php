<?php
$_ENV = getenv('ENV') ?: 'dev';
require_once(__DIR__ . "/../vendor/autoload.php");
require_once(__DIR__ . "/config." . $_ENV . ".php");

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once(__DIR__ . "/phpbb_login.php");

$_DEV_MODE = $user->data["username"] == "Tiago" || $user->data["username"] == "Filipe" || $user->data["username"] == "jbresolin";

if (!$_DEV_MODE && $_SERVER['REDIRECT_EVENT'] == '17SE') {
   include_once(__DIR__ . "/../html/obras.html");
   exit();
}