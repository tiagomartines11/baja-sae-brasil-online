<?php
/**
 * The engine's half of scripts/contagem-invariante.mjs. No database.
 *
 * Reads simulated races from standard input, one JSON object per line:
 *
 *   {"semente": 1, "largada_ms": ..., "fim_ms": ..., "carros": [..],
 *    "travessias": [[carro, ts_ms], ...],          what really happened
 *    "entradas":   [[fonte_id, carro, ts_ms], ...], what the counters typed
 *    "paradas":    [[tipo, carro, ts_ms], ...]}     what the pit log recorded
 *
 * For each race it runs the whole engine at several moments of the race,
 * each time with only the entries typed up to that moment and with NO
 * reviewer decision, in the default publish mode ("confirmadas"), and
 * checks THE INVARIANT:
 *
 *   no car has more official laps than it has really completed.
 *
 * Prints one JSON line per race: {"semente", "violacoes": [...], "a_menos": n}.
 * Each violation is {carro, ate_ms, voltas, verdade}: at that moment the
 * car had more official laps than true ones. "a_menos": crossings not
 * counted at the end of the race, which is allowed: they wait for a person.
 */

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;

require_once __DIR__ . '/../vendor/autoload.php';

const CHECKPOINTS = 6;
/** A crossing typed by this moment happened at most this much later (counters type up to 0.5 s early). */
const TYPED_AHEAD_MS = 1000;

/**
 * @param array<string, mixed> $race
 * @return array{violacoes: array<int, array{carro: int, ate_ms: int, voltas: int, verdade: int}>, a_menos: int}
 */
function checkRace(array $race): array
{
    $startMs = (int) $race['largada_ms'];
    $endMs = (int) $race['fim_ms'];
    $sourceIds = array_values(array_unique(array_column($race['entradas'], 0)));
    sort($sourceIds);

    $violations = [];
    $shortAtTheEnd = 0;
    for ($checkpoint = 1; $checkpoint <= CHECKPOINTS; $checkpoint++) {
        // The last checkpoint is a little after the end, when every entry is in.
        $nowMs = $checkpoint === CHECKPOINTS ? $endMs + 60000 : $startMs + intdiv(($endMs - $startMs) * $checkpoint, CHECKPOINTS);

        $input = new EngineInput(new Settings());
        $input->nowMs = $nowMs;
        $input->validCars = $race['carros'];
        foreach ($sourceIds as $order => $sourceId) {
            // Every counter has uploaded everything up to now.
            $input->sources[] = new Source($sourceId, 'Contagem ' . ($order + 1), Source::TIPO_MANUAL, Source::TEMPO_HUMANA, true, $order, $nowMs, $nowMs);
        }
        $input->ocorrencias[] = new Ocorrencia(1, Ocorrencia::LARGADA, $startMs);
        foreach ($race['paradas'] as $index => [$tipo, $carro, $tsMs]) {
            if ($tsMs <= $nowMs) {
                $input->ocorrencias[] = new Ocorrencia($index + 2, $tipo, $tsMs, $carro);
            }
        }
        foreach ($race['entradas'] as $index => [$sourceId, $carro, $tsMs]) {
            if ($tsMs <= $nowMs) {
                $input->entries[] = new Entry($index + 1, $sourceId, $sourceId, $carro, $tsMs);
            }
        }

        $result = Engine::run($input);

        $trueCrossings = [];
        foreach ($race['travessias'] as [$carro, $tsMs]) {
            if ($tsMs <= $nowMs + TYPED_AHEAD_MS) {
                $trueCrossings[$carro] = ($trueCrossings[$carro] ?? 0) + 1;
            }
        }
        foreach ($result->cars as $carro => $state) {
            // The first crossing is pass zero: laps = crossings - 1.
            $trueLaps = max(0, ($trueCrossings[$carro] ?? 0) - 1);
            if ($state->voltas > $trueLaps) {
                $violations[] = ['carro' => $carro, 'ate_ms' => $nowMs, 'voltas' => $state->voltas, 'verdade' => $trueLaps];
            }
            if ($checkpoint === CHECKPOINTS) {
                $shortAtTheEnd += max(0, $trueLaps - $state->voltas);
            }
        }
    }
    return ['violacoes' => $violations, 'a_menos' => $shortAtTheEnd];
}

while (($line = fgets(STDIN)) !== false) {
    $race = json_decode($line, true);
    if (!is_array($race)) {
        continue;
    }
    echo json_encode(['semente' => $race['semente']] + checkRace($race)), "\n";
}
