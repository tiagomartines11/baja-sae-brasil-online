<?php
$serviceContainer = \Propel\Runtime\Propel::getServiceContainer();
$serviceContainer->checkVersion('2.0.0-dev');
$serviceContainer->setAdapterClass('resultados', 'mysql');
$manager = new \Propel\Runtime\Connection\ConnectionManagerSingle();
$manager->setConfiguration(array (
  'classname' => 'Propel\\Runtime\\Connection\\ConnectionWrapper',
  'dsn' => 'mysql:host=localhost;dbname=baja_resultados',
  'user' => 'resultados',
  'password' => '****',
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
$manager->setName('resultados');
$serviceContainer->setConnectionManager('resultados', $manager);
$serviceContainer->setDefaultDatasource('resultados');

$_smtpPassword = '****';
$_oneSignalAuth = '*****';

$_remoteKey = '*******';
$_remoteValidFor = '18BR';

$_recaptchaKey = '*******';
$_tEmail = '*****@gmail.com';
$_fEmail = '*****@gmail.com';

//use Baja\Model\Map\InputTableMap;
//use Propel\Runtime\Propel;
//
//use Monolog\Logger;
//use Monolog\Handler\StreamHandler;
//$logger = new Logger('defaultLogger');
//$logger->pushHandler(new StreamHandler('/var/log/propel.log'));
//Propel::getServiceContainer()->setLogger('defaultLogger', $logger);
//$logger->warning('Foo');
//$con = Propel::getWriteConnection(InputTableMap::DATABASE_NAME);
//$con->useDebug(true);
