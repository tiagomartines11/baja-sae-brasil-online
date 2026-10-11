<?php
/**
 * Fills a laps prova of the DEV database with a whole made-up race, so the
 * load test (contagem-load-test.mjs) runs against a realistic amount of
 * data: every present team, about 70 laps each, three counters.
 *
 *   docker compose exec --user "$(id -u):$(id -g)" baja-app \
 *       php scripts/contagem-load-seed.php 26BR END
 *
 * It WIPES the lap counting data of that prova first (through the same
 * "Zerar corrida" code the page uses, so the old rows go to the archive
 * table). It refuses to run when ENV is "prod".
 *
 * The race it writes started 3 h 20 min ago and is still running, so the
 * load test can add laps "now".
 *
 * A third argument asks for a field of that many cars, the size of a full
 * national event:
 *
 *       php scripts/contagem-load-seed.php 26BR END 64
 *
 * When the event has fewer present teams, stand-in teams named "Carga NNN"
 * (numbers from 900 up) are added to make up the difference. Remove them
 * again with:
 *
 *       php scripts/contagem-load-seed.php 26BR END limpar
 */

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
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

const RACE_AGE_SECONDS = 12000;
const BASE_LAP_SECONDS = 170;

if (Env::isProduction()) {
    fwrite(STDERR, "Refusing to run: this is a production environment.\n");
    exit(1);
}
[$eventoId, $provaId] = [$argv[1] ?? '', $argv[2] ?? ''];
$prova = ProvaQuery::create()->findPk([$eventoId, $provaId]);
if ($prova === null || $prova->getType() !== 'laps') {
    fwrite(STDERR, "Usage: php scripts/contagem-load-seed.php <evento> <prova>   (a prova of type laps)\n");
    exit(1);
}

const FIRST_STAND_IN_NUMBER = 900;
const STAND_IN_NAME = 'Carga ';

$connection = Propel::getWriteConnection('resultados');
$service = ContagemService::forProva($prova);
$service->resetRace('load-seed');

// Stand-in teams of an earlier run always go first: with "limpar" that is
// all there is to do, and otherwise the field is built to the size asked.
$connection->prepare('DELETE FROM equipe WHERE evento_id = ? AND equipe_id >= ? AND equipe LIKE ?')
    ->execute([$eventoId, FIRST_STAND_IN_NUMBER, STAND_IN_NAME . '%']);
if (($argv[3] ?? '') === 'limpar') {
    echo "Race wiped and stand-in teams removed.\n";
    exit(0);
}
$wantedCars = (int) ($argv[3] ?? 0);
$missingCars = $wantedCars - count($service->loadTeams(true));
for ($standIn = 0; $standIn < $missingCars; $standIn++) {
    $number = FIRST_STAND_IN_NUMBER + $standIn;
    $connection->prepare('INSERT INTO equipe (evento_id, equipe_id, escola, equipe, presente) VALUES (?, ?, ?, ?, 1)')
        ->execute([$eventoId, $number, 'Teste de carga', STAND_IN_NAME . $number]);
}

$nowMs = Clock::nowMs();
$startMs = $nowMs - RACE_AGE_SECONDS * 1000;
$service->withLock(fn() => $service->recordOcorrencia(Ocorrencia::LARGADA, $startMs, null, 'load-seed'));

$sources = ContagemFonteQuery::create()
    ->filterByEventoId($eventoId)->filterByProvaId($provaId)->filterByTipo('manual')->filterByAtiva(true)
    ->orderByOrdem()->find();

// One stand-in device per source, marked as fully uploaded and closed, so
// the engine does not wait for it.
$deviceIdBySource = [];
foreach ($sources as $source) {
    $connection->prepare(
        'INSERT INTO contagem_dispositivo (fonte_id, token_hash, usuario, rotulo, criado_em_ms, visto_em_ms, sincronizado_ate_ms, fechado_em_ms)
         VALUES (?, ?, NULL, ?, ?, ?, ?, ?)'
    )->execute([$source->getFonteId(), hash('sha256', random_bytes(32)), 'load-seed', $nowMs, $nowMs, $nowMs, $nowMs]);
    $deviceIdBySource[$source->getFonteId()] = (int) $connection->lastInsertId();
}

mt_srand(2026);
$insert = $connection->prepare(
    'INSERT INTO contagem_passagem (fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_dispositivo_ms,
         ts_ms, qualidade_tempo, digitacao_ms, modo_entrada, recebido_em_ms)
     VALUES (?, ?, ?, ?, ?, ?, \'exato\', 800, ?, ?)'
);
$entries = 0;
$connection->beginTransaction();
foreach (array_keys($service->carRoster()->classified()) as $car) {
    $carFactor = mt_rand(85, 130) / 100;
    $passMs = $startMs + (int) (mt_rand(30, 100) / 100 * BASE_LAP_SECONDS * 1000 * $carFactor);
    while ($passMs < $nowMs - 60000) {
        foreach ($sources as $source) {
            // About one crossing in fifty is missed by a counter.
            if (mt_rand(1, 50) === 1) {
                continue;
            }
            $typedAtMs = $passMs + mt_rand(-400, 1200);
            $uuid = sprintf('%08x-%04x-4%03x-a%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), $entries);
            $insert->execute([$source->getFonteId(), $deviceIdBySource[$source->getFonteId()], $uuid, $car, $typedAtMs, $typedAtMs, 'teclado', $typedAtMs]);
            $entries++;
        }
        $passMs += (int) (BASE_LAP_SECONDS * 1000 * $carFactor * mt_rand(94, 106) / 100);
    }
}
$connection->commit();

$startedAt = hrtime(true);
$result = $service->withLock(fn() => $service->recomputeAndPublish());
printf(
    "Seeded %d entries for %d cars on %d sources; full recount and publish took %.0f ms; leader has %d laps.\n",
    $entries,
    count($result->cars),
    count($sources),
    (hrtime(true) - $startedAt) / 1e6,
    max(array_map(fn($car) => $car->voltas, $result->cars))
);
