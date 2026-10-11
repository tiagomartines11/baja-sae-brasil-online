<?php
/**
 * Helper of the Node simulation runner (scripts/contagem-simular.mjs). It
 * does, inside the app container, the two things the runner cannot do over
 * the network without a person: set a race up, and read what was published.
 *
 *   php scripts/contagem-simular-apoio.php <evento> <prova> preparar <largada_ms> <contadores>
 *
 *       WIPES the lap counting data of the prova ("Zerar corrida", so the
 *       old rows go to the archive table), starts a race at <largada_ms>,
 *       and creates one device for each of the first <contadores> manual
 *       sources plus one for the pit log. Prints, as JSON, the tokens of
 *       those devices and what the simulation needs to know about the
 *       prova (car lists, matching window, counting rule).
 *
 *   php scripts/contagem-simular-apoio.php <evento> <prova> publicado
 *
 *       Prints, as JSON, the lap count each present car has in the scoring
 *       system right now (Input.dados.VOLTAS; 0 for a car without a row).
 *
 * It refuses to run in production, and it refuses a prova that does not
 * allow simulation (`contagem.permitir_simulacao`). It never changes that
 * setting, or any other: a person must switch it on, on the settings page.
 */

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\ProvaQuery;
use Baja\Util\Env;
use Propel\Runtime\Propel;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/config.php';
// Score formulas mention these bare words; the web bootstrap defines them.
foreach (['Pos', 'EquipeNum', 'Equipe'] as $constant) {
    defined($constant) || define($constant, $constant);
}

const SIMULATION_USER = 'simulacao';

function refuse(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if (Env::isProduction()) {
    refuse('Refusing to run: this is a production environment.');
}
[$eventoId, $provaId, $command] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$prova = ProvaQuery::create()->findPk([$eventoId, $provaId]);
if ($prova === null || $prova->getType() !== 'laps') {
    refuse('Usage: php scripts/contagem-simular-apoio.php <evento> <prova> preparar <largada_ms> <contadores> | publicado');
}
$service = ContagemService::forProva($prova);
$settings = $service->getSettings();
if (!$settings->permitirSimulacao) {
    refuse('Esta prova não permite simulação. Ligue "Permitir simulação" nas configurações da contagem (só para ensaios) e tente de novo.');
}

if ($command === 'publicado') {
    $statement = Propel::getReadConnection('resultados')->prepare(
        'SELECT e.equipe_id, i.dados
           FROM equipe e
      LEFT JOIN input i ON i.evento_id = e.evento_id AND i.equipe_id = e.equipe_id AND i.prova_id = ?
          WHERE e.evento_id = ? AND e.presente = 1
       ORDER BY e.equipe_id'
    );
    $statement->execute([$provaId, $eventoId]);
    $lapsByCar = [];
    foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$car, $dados]) {
        $decoded = (array) json_decode((string) ($dados ?? ''), true);
        $lapsByCar[(int) $car] = (int) ($decoded['VOLTAS'] ?? 0);
    }
    echo json_encode($lapsByCar), "\n";
    exit(0);
}

if ($command !== 'preparar') {
    refuse('Unknown command: ' . $command);
}
$startMs = (int) ($argv[4] ?? 0);
$counters = max(1, (int) ($argv[5] ?? 3));
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
        'token' => DeviceTokens::issue($source, SIMULATION_USER, 'Simulação (Node)'),
    ];
}
$roster = $service->carRoster();

echo json_encode([
    'largada_ms' => $startMs,
    'carros' => array_keys($roster->classified()),
    'nao_classificados' => array_keys($roster->unclassified()),
    'janela_ms' => $settings->janelaMs,
    'passagem_inicial' => $settings->passagemInicial,
    'abastecimento' => $settings->abastecimento,
    'fontes' => $devices,
    'box_token' => DeviceTokens::issue(DeviceTokens::boxSourceFor($prova), SIMULATION_USER, 'Simulação (Node)'),
]), "\n";
