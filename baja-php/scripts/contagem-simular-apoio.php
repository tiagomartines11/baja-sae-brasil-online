<?php
/**
 * Helper of the Node simulation runner (scripts/contagem-simular.mjs). It
 * does, inside the app container, the things the runner cannot do over the
 * network without a person.
 *
 *   php scripts/contagem-simular-apoio.php <evento> <prova> preparar <largada_ms> <contadores> [sensor]
 *
 *       WIPES the lap counting data of the prova ("Zerar corrida", so the
 *       old rows go to the archive table), starts a race at <largada_ms>,
 *       and creates one device for each of the first <contadores> manual
 *       sources plus one for the pit log. With "sensor", also a timing
 *       sensor source ("Sensor (simulação)", taking part, precise; made
 *       once and reused) and a device for it. Prints, as JSON, the tokens of
 *       those devices and what the simulation needs to know about the
 *       prova (car lists, matching window, counting rule).
 *
 *   php scripts/contagem-simular-apoio.php <evento> <prova> publicado
 *
 *       Prints, as JSON, what each present car has in the scoring system
 *       right now: {"<carro>": {"voltas": n, "last": seconds}}.
 *
 *   php scripts/contagem-simular-apoio.php <evento> <prova> reconstruir
 *
 *       "Reconstruir": throws the computed laps away and works everything
 *       out again from the raw entries. Prints {"ok": true}.
 *
 *   php scripts/contagem-simular-apoio.php <evento> <prova> encerrar
 *
 *       Revokes the devices "preparar" created, so their tokens are dead.
 *
 * It refuses to run anywhere but a development machine (ENV must say
 * "dev"), from anything but the command line, on a prova that does not
 * allow simulation (`contagem.permitir_simulacao`), and in any publish
 * mode but "confirmadas" (the mode whose guarantees the runner checks).
 * It never changes a setting: a person must, on the settings page.
 */

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Util\Env;
use Propel\Runtime\Propel;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/config.php';
// Score formulas mention these bare words; the web bootstrap defines them.
foreach (['Pos', 'EquipeNum', 'Equipe'] as $constant) {
    defined($constant) || define($constant, $constant);
}

const SIMULATION_USER = 'simulacao';
const DEVICE_LABEL = 'Simulação (Node)';

function refuse(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    refuse('This script only runs from the command line.');
}
if (!Env::isDevelopment()) {
    refuse('Refusing to run: this is not a development environment (ENV is not "dev").');
}
[$eventoId, $provaId, $command] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$prova = ProvaQuery::create()->findPk([$eventoId, $provaId]);
if ($prova === null || $prova->getType() !== 'laps') {
    refuse('Usage: php scripts/contagem-simular-apoio.php <evento> <prova> preparar <largada_ms> <contadores> | publicado | reconstruir | encerrar');
}
$service = ContagemService::forProva($prova);
$settings = $service->getSettings();
$connection = Propel::getWriteConnection('resultados');

if ($command === 'encerrar') {
    $statement = $connection->prepare(
        'UPDATE contagem_dispositivo d JOIN contagem_fonte f ON f.fonte_id = d.fonte_id
            SET d.revogado_em_ms = ?
          WHERE f.evento_id = ? AND f.prova_id = ? AND d.usuario = ? AND d.rotulo = ? AND d.revogado_em_ms IS NULL'
    );
    $statement->execute([(int) round(microtime(true) * 1000), $eventoId, $provaId, SIMULATION_USER, DEVICE_LABEL]);
    echo json_encode(['revogados' => $statement->rowCount()]), "\n";
    exit(0);
}

if (!$settings->permitirSimulacao) {
    refuse('Esta prova não permite simulação. Ligue "Permitir simulação" nas configurações da contagem (só para ensaios) e tente de novo.');
}
if ($settings->modoPublicacao !== Settings::MODO_CONFIRMADAS) {
    refuse('O modo de publicação desta prova é "' . $settings->modoPublicacao . '". Este teste só roda em "confirmadas", o modo cujas garantias ele confere.');
}

if ($command === 'publicado') {
    $statement = $connection->prepare(
        'SELECT e.equipe_id, i.dados
           FROM equipe e
      LEFT JOIN input i ON i.evento_id = e.evento_id AND i.equipe_id = e.equipe_id AND i.prova_id = ?
          WHERE e.evento_id = ? AND e.presente = 1
       ORDER BY e.equipe_id'
    );
    $statement->execute([$provaId, $eventoId]);
    $byCar = [];
    foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$car, $dados]) {
        $decoded = (array) json_decode((string) ($dados ?? ''), true);
        $byCar[(int) $car] = ['voltas' => (int) ($decoded['VOLTAS'] ?? 0), 'last' => (int) ($decoded['LAST'] ?? 0)];
    }
    echo json_encode($byCar), "\n";
    exit(0);
}

if ($command === 'reconstruir') {
    $service->rebuildLaps();
    echo json_encode(['ok' => true]), "\n";
    exit(0);
}

if ($command !== 'preparar') {
    refuse('Unknown command: ' . $command);
}
$startMs = (int) ($argv[4] ?? 0);
$counters = max(1, (int) ($argv[5] ?? 3));
$withSensor = ($argv[6] ?? '') === 'sensor';
if ($startMs <= 0) {
    refuse('The start time (milliseconds since 1970) is missing.');
}

$service->resetRace(SIMULATION_USER);
$service->withLock(fn() => $service->recordOcorrencia(Ocorrencia::LARGADA, $startMs, null, SIMULATION_USER));

$sources = ContagemFonteQuery::create()
    ->filterByEventoId($eventoId)->filterByProvaId($provaId)
    ->filterByTipo(Source::TIPO_MANUAL)->filterByAtiva(true)
    ->orderByOrdem()->limit($counters)->find();
if (count($sources) < $counters) {
    refuse('The prova has only ' . count($sources) . ' active manual sources; ' . $counters . ' were asked for.');
}

$devices = [];
foreach ($sources as $source) {
    $devices[] = [
        'id' => (int) $source->getFonteId(),
        'nome' => (string) $source->getNome(),
        'token' => DeviceTokens::issue($source, SIMULATION_USER, DEVICE_LABEL),
    ];
}
$roster = $service->carRoster();

// A timing sensor for the rehearsal: made once, taking part from the start
// (a real one starts as an observer and is promoted on the Fontes page).
$sensor = null;
if ($withSensor) {
    $sensorSource = ContagemFonteQuery::create()
        ->filterByEventoId($eventoId)->filterByProvaId($provaId)
        ->filterByTipo(Source::TIPO_SENSOR)->filterByNome('Sensor (simulação)')->findOne();
    if ($sensorSource === null) {
        $sensorSource = new \Baja\Model\ContagemFonte();
        $sensorSource->setEventoId($eventoId);
        $sensorSource->setProvaId($provaId);
        $sensorSource->setNome('Sensor (simulação)');
        $sensorSource->setTipo(Source::TIPO_SENSOR);
        $sensorSource->setQualidadeTempo(Source::TEMPO_PRECISA);
        $sensorSource->setPapel(Source::PAPEL_VOTANTE);
        $sensorSource->setOrdem(99);
    }
    $sensorSource->setAtiva(true);
    $sensorSource->save();
    $sensor = [
        'id' => (int) $sensorSource->getFonteId(),
        'nome' => (string) $sensorSource->getNome(),
        'token' => DeviceTokens::issue($sensorSource, SIMULATION_USER, DEVICE_LABEL),
    ];
}

// The upload endpoint checks, on every upload of stops, that the pit
// phone's user still holds contagem.pit_log on the prova. The simulated pit
// phone's user gets the pit_logger role there (dev stacks only, see the
// checks above), written straight to the tables like the migration's
// grants (granted_by NULL), with its audit row.
$simulationUser = UserQuery::create()->findOneByUsername(SIMULATION_USER) ?? (new User())->setUsername(SIMULATION_USER);
if ($simulationUser->isNew()) {
    $simulationUser->save();
}
$pitNode = ScopeNode::prova((string) $prova->getEventoId(), (string) $prova->getProvaId());
if (!Authorization::can($simulationUser, Permission::CONTAGEM_PIT_LOG, $pitNode)) {
    $con = Propel::getWriteConnection('resultados');
    $con->prepare("INSERT INTO user_role (user_id, role_id, scope_type, scope_id, granted_by, granted_at, valid_until)
                   SELECT ?, role_id, 'prova', ?, NULL, NOW(), NULL FROM role WHERE code = 'pit_logger'")
        ->execute([(int) $simulationUser->getUserId(), $pitNode->id]);
    $con->prepare("INSERT INTO role_audit (occurred_at, actor_user_id, action, grant_id, role_id, target_user_id, scope_type, scope_id, details)
                   SELECT NOW(), NULL, 'grant', LAST_INSERT_ID(), role_id, ?, 'prova', ?, JSON_OBJECT('role', 'pit_logger', 'source', 'scripts/contagem-simular-apoio.php') FROM role WHERE code = 'pit_logger'")
        ->execute([(int) $simulationUser->getUserId(), $pitNode->id]);
}

echo json_encode([
    'largada_ms' => $startMs,
    'carros' => array_keys($roster->classified()),
    'nao_classificados' => array_keys($roster->unclassified()),
    'janela_ms' => $settings->janelaMs,
    'passagem_inicial' => $settings->passagemInicial,
    'abastecimento' => $settings->abastecimento,
    'fontes' => $devices,
    'box_token' => DeviceTokens::issue(DeviceTokens::boxSourceFor($prova), SIMULATION_USER, DEVICE_LABEL),
    'sensor' => $sensor,
]), "\n";
