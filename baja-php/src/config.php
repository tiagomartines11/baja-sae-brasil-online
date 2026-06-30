<?php
// Propel + app-secret config for baja-app.
// Environment-agnostic: all environment-varying values are read from
// environment variables (injected by docker-compose from .env). Dev vs prod
// differ only in .env values, not in this file.
//
// DB credentials: baja-app connects to baja_resultados via Propel as the
// `resultados` MySQL user. That same MySQL account is also used by the phpBB
// session shim (full grants on baja_resultados, SELECT on phpbb_baja — "one
// account, two roles"). Because it is a single shared account, this
// connection reuses the PHPBB_DB_HOST / PHPBB_DB_USER / PHPBB_DB_PASS env
// vars rather than defining parallel ones.
//
// IMPORTANT: dbname is hardcoded to baja_resultados (Propel's database) and
// must NOT be taken from PHPBB_DB_NAME, which is phpbb_baja (the shim's
// read-only target). Using PHPBB_DB_NAME here would point Propel at the
// wrong database.
//
// phpBB session shim config is handled separately via env vars (PHPBB_DB_*,
// PHPBB_COOKIE_PREFIX, REDIS_*, SESSION_CACHE_TTL_SECONDS). See
// src/Baja/Auth/SessionStore.php.

$serviceContainer = \Propel\Runtime\Propel::getServiceContainer();
$serviceContainer->checkVersion(2);
$serviceContainer->setAdapterClass('resultados', 'mysql');
$manager = new \Propel\Runtime\Connection\ConnectionManagerSingle('resultados');
$manager->setConfiguration(array (
  'classname' => 'Propel\\Runtime\\Connection\\ConnectionWrapper',
  'dsn' => 'mysql:host=' . getenv('PHPBB_DB_HOST') . ';dbname=baja_resultados',
  'user' => getenv('PHPBB_DB_USER'),
  'password' => getenv('PHPBB_DB_PASS'),
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

// App-level secrets — values come from .env via docker-compose environment.
$_oneSignalAuth = getenv('ONESIGNAL_AUTH');
$_remoteKey     = getenv('REMOTE_KEY');
$_recaptchaKey  = getenv('RECAPTCHA_KEY');
$_tEmail        = getenv('SYSADMIN_1_EMAIL');
$_bEmail        = getenv('SYSADMIN_2_EMAIL');
