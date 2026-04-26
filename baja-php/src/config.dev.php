<?php
// Dev-environment Propel + app-secret config.
// Loaded by src/bootstrap.php when env var ENV=dev.
//
// Public URL configuration is via env vars (BAJA_PUBLIC_DOMAIN,
// BAJA_PUBLIC_SCHEME). See \Baja\Url for the assembly helpers.
//
// NOTE: phpBB session shim configuration is handled separately via env vars
// (PHPBB_DB_HOST, PHPBB_DB_PORT, PHPBB_DB_NAME, PHPBB_DB_USER, PHPBB_DB_PASS,
// PHPBB_COOKIE_PREFIX, REDIS_HOST, REDIS_PORT, SESSION_CACHE_TTL_SECONDS).
// See src/Baja/Auth/SessionStore.php for the full list. Those env vars are
// set in baja-infra/docker-compose.yml under the baja-app service. The shim
// has its own PDO connection scoped to phpBB tables (read-only via the
// 'resultados' MySQL user's SELECT grant on phpbb_baja.*); it does NOT use
// Propel.

$serviceContainer = \Propel\Runtime\Propel::getServiceContainer();
$serviceContainer->checkVersion(2);
$serviceContainer->setAdapterClass('resultados', 'mysql');
$manager = new \Propel\Runtime\Connection\ConnectionManagerSingle('resultados');
$manager->setConfiguration(array (
  'classname' => 'Propel\\Runtime\\Connection\\ConnectionWrapper',
  'dsn' => 'mysql:host=mysql;dbname=baja_resultados',
  'user' => 'resultados',
  'password' => 'devresultados',
  'attributes' =>
  array (
    'ATTR_EMULATE_PREPARES' => false,
    'ATTR_TIMEOUT' => 30,
  ),
  'settings' =>
  array (
    'charset' => 'utf8mb4',
    'queries' =>
    array (
      'utf8' => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, COLLATION_CONNECTION = utf8mb4_unicode_ci, COLLATION_DATABASE = utf8mb4_unicode_ci, COLLATION_SERVER = utf8mb4_unicode_ci',
    ),
  ),
  'model_paths' =>
  array (
    0 => 'src',
    1 => 'vendor',
  ),
));
$serviceContainer->setConnectionManager($manager);
$serviceContainer->setDefaultDatasource('resultados');
require_once __DIR__ . '/loadDatabase.php';

// Dev placeholder values for app-level secrets
$_oneSignalAuth = 'dev-onesignal-auth';
$_remoteKey = 'dev-remote-key';
$_recaptchaKey = 'dev-recaptcha-key';
$_tEmail = 'dev-tiago@example.com';
$_bEmail = 'dev-bresolin@example.com';
