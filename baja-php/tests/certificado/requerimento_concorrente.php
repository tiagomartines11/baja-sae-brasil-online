<?php
/**
 * Helper for gravador_test.php: submit one report, at an agreed moment.
 *
 * Not a test on its own (run.php only picks up files ending in _test.php).
 * gravador_test.php starts several copies of this script at once, as separate
 * PHP processes, to imitate several people — or one person pressing a button
 * several times — reaching the server in the same instant. Separate processes
 * matter: each one opens its own database connection, exactly like separate
 * web requests do, so they really can race each other.
 *
 *   php requerimento_concorrente.php <start-at> <json form fields> <eventos json>
 *
 * <start-at> is a Unix time with microseconds. Every copy waits until then
 * before doing anything, so that they all hit the database together rather
 * than one after the other as they happen to finish starting up.
 *
 * Prints one word: what Gravador::registrar() decided (see Registro).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/config.php';

use Baja\Certificado\Requerimento\Formulario;
use Baja\Certificado\Requerimento\Gravador;

$inicio  = (float) $argv[1];
$post    = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$eventos = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);

$formulario = new Formulario($post, $eventos);

// Open the connection now, while waiting, so that the time spent connecting
// does not spread the copies apart again.
\Propel\Runtime\Propel::getWriteConnection('resultados');

while (microtime(true) < $inicio) {
    usleep(1000);
}

echo Gravador::registrar($formulario)->situacao;
