<?php
declare(strict_types=1);

// Shim replacement for the legacy phpBB bootstrap.
// Establishes $GLOBALS['user'] and $GLOBALS['auth'] using a native PDO/Redis-
// backed session store, instead of including phpBB's framework via
// forum/common.php. See docs/phpbb-coupling-report.md for the inventory of
// what baja-app actually uses from phpBB, and src/Baja/Auth/* for the shim.

use Baja\Auth\PhpbbAuthShim;
use Baja\Auth\PhpbbSessionShim;
use Baja\Auth\SessionStore;

// phpBB constants used by Baja\Session::setSession() and PhpbbAuthShim::login().
// Values match phpBB 3.3's includes/constants.php.
if (!defined('LOGIN_SUCCESS'))        define('LOGIN_SUCCESS',        1);
if (!defined('LOGIN_ERROR_USERNAME')) define('LOGIN_ERROR_USERNAME', 10);
if (!defined('LOGIN_ERROR_PASSWORD')) define('LOGIN_ERROR_PASSWORD', 11);

// No default on purpose. This must match phpbb_config.cookie_name for the
// baja forum; any other value makes PhpbbSessionShim look for a cookie that
// was never set, so it returns an unpopulated $user->data and the user is
// bounced back to login with nothing logged anywhere. Fail at boot instead.
$cookiePrefix = SessionStore::requireEnv('PHPBB_COOKIE_PREFIX')['PHPBB_COOKIE_PREFIX'];

$store = new SessionStore();
$user  = new PhpbbSessionShim($store, $cookiePrefix);
$auth  = new PhpbbAuthShim($store);

$user->session_begin();
$auth->acl($user->data); // No-op; kept for API parity with the legacy bootstrap.

$GLOBALS['user'] = $user;
$GLOBALS['auth'] = $auth;
