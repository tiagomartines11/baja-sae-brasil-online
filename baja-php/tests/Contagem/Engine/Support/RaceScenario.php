<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\CountResult;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;

/**
 * A small race written by hand, for tests.
 *
 * It lets a test read like the story it tells:
 *
 *     $race = RaceScenario::withCounters(3);        // "Contagem 1", "2" and "3"
 *     $race->start('00:00');                        // race control starts the race
 *     $race->pass(41, '03:10');                     // all three counters record car 41
 *     $race->entry(1, 14, '06:20');                 // only counter 1 records car 14
 *     $result = $race->run('10:00');                // what does the engine say at minute 10?
 *
 * Times are written as "minutes:seconds" on the race clock ("03:10" is three
 * minutes and ten seconds after the start; "03:10.5" adds half a second;
 * "-00:30" is thirty seconds BEFORE the start).
 *
 * Unless a test says otherwise, every counter is online and fully uploaded,
 * so the engine knows everything each counter recorded.
 */
final class RaceScenario
{
    // void(), reassign(), confirm(), useTimeOf(), addPass(), excludeSource(), undo(), accept()
    use ReviewerActions;

    /** The moment "00:00" stands for. Any fixed date works; nothing depends on it. */
    public const CLOCK_ZERO_MS = 1760000000000;

    /** A moment far after any test race, used for "this counter has uploaded everything". */
    private const FAR_FUTURE_MS = self::CLOCK_ZERO_MS + 100 * 3600 * 1000;

    public Settings $settings;

    /** @var array<int, Source> */
    private array $sources = [];
    /** @var Entry[] */
    private array $entries = [];
    /** @var Ocorrencia[] */
    private array $ocorrencias = [];
    /** @var array<int, array<int, int>> */
    private array $totals = [];
    /** @var int[] */
    private array $validCars = [];
    /** @var int[] */
    private array $unclassifiedCars = [];
    /** @var array<int, true> sources whose "last seen" time the test set by hand */
    private array $lastSeenSetByTest = [];

    private int $nextEntryId = 1;
    private int $nextOcorrenciaId = 1;

    public function __construct()
    {
        $this->settings = new Settings();
    }

    /** A race with N human counters, with source ids 1..N, named "Contagem 1".."Contagem N". */
    public static function withCounters(int $count = 3): self
    {
        $scenario = new self();
        for ($id = 1; $id <= $count; $id++) {
            $scenario->addSource($id, 'Contagem ' . $id);
        }
        return $scenario;
    }

    /** Converts a race-clock time such as "03:10" into the milliseconds the engine works with. */
    public static function ms(string $clock): int
    {
        $sign = 1;
        if ($clock[0] === '-') {
            $sign = -1;
            $clock = substr($clock, 1);
        }
        [$minutes, $seconds] = explode(':', $clock);
        return self::CLOCK_ZERO_MS + $sign * (int) round(((int) $minutes * 60 + (float) $seconds) * 1000);
    }

    public function addSource(
        int $id,
        string $nome,
        string $tipo = Source::TIPO_MANUAL,
        string $qualidadeTempo = Source::TEMPO_HUMANA
    ): Source {
        $source = new Source($id, $nome, $tipo, $qualidadeTempo, true, $id, self::FAR_FUTURE_MS, null);
        $this->sources[$id] = $source;
        return $source;
    }

    public function source(int $id): Source
    {
        return $this->sources[$id];
    }

    /** Race control starts the race. Calling it again is "the start time was edited". */
    public function start(string $at = '00:00'): int
    {
        return $this->ocorrencia(Ocorrencia::LARGADA, $at);
    }

    /** One counter records one car. Returns the id of the stored entry. */
    public function entry(int $source, int $car, string $at, ?int $device = null): int
    {
        $id = $this->nextEntryId++;
        // By default each counter uses a single device, numbered after the source.
        $this->entries[] = new Entry($id, $source, $device ?? $source * 10, $car, self::ms($at));
        return $id;
    }

    /**
     * One counter records one car on a phone that could not take its time
     * from the server: typed offline, and the page was reloaded before it
     * got a connection. $quality says how good the time is:
     * Entry::TEMPO_ESTIMADO (worked out from an older sync, roughly right)
     * or Entry::TEMPO_DESCONHECIDO (whatever the phone's clock said).
     */
    public function entryWithTimeQuality(int $source, int $car, string $at, string $quality): int
    {
        $id = $this->entry($source, $car, $at);
        $this->entries[count($this->entries) - 1]->qualidadeTempo = $quality;
        return $id;
    }

    /**
     * A car crosses the line and the listed counters all record it at (about)
     * the same time. Returns the entry ids keyed by source id.
     *
     * @param int[] $seenBy source ids; by default every human counter
     * @return array<int, int>
     */
    public function pass(int $car, string $at, ?array $seenBy = null): array
    {
        $ids = [];
        foreach ($seenBy ?? $this->countingSourceIds() as $source) {
            $ids[$source] = $this->entry($source, $car, $at);
        }
        return $ids;
    }

    /**
     * The first time each of these cars crosses the counting line after the
     * start, recorded by every counter (or by the listed ones).
     *
     * WHY TESTS NEED IT: the counting line is after the start line, so a
     * car's first crossing completes no lap. It is the car's "pass zero",
     * and laps = official passes - 1. A test about "car 41's lap at 03:10"
     * therefore first lets car 41 cross once, shortly after the start.
     *
     * @param int[] $cars
     * @param int[]|null $seenBy source ids; by default every human counter
     */
    public function firstCrossing(array $cars, string $at = '00:20', ?array $seenBy = null): void
    {
        foreach ($cars as $car) {
            $this->pass($car, $at, $seenBy);
        }
    }

    /**
     * A field of well-behaved cars, so the engine has enough laps to know
     * the pace of the race (it needs at least eight recent laps before it
     * will say what a "normal" lap is).
     *
     * Every car in $cars crosses the line every $lapSeconds, $crossings
     * times, and every counter records every crossing. Cars follow each
     * other $gapSeconds apart. The first car's first crossing is at
     * $firstPassAt. Remember that the first crossing is pass zero: six
     * crossings are five laps.
     *
     * @param int[] $cars
     */
    public function steadyField(array $cars, int $lapSeconds, int $crossings, string $firstPassAt = '03:00', int $gapSeconds = 7): void
    {
        $firstPassMs = self::ms($firstPassAt) - self::CLOCK_ZERO_MS;
        foreach (array_values($cars) as $position => $car) {
            for ($lap = 0; $lap < $crossings; $lap++) {
                $passSeconds = intdiv($firstPassMs, 1000) + $position * $gapSeconds + $lap * $lapSeconds;
                $this->pass($car, self::clock($passSeconds));
            }
        }
    }

    /** The opposite of ms(): seconds on the race clock written as "mm:ss". */
    public static function clock(int $secondsAfterStart): string
    {
        return sprintf('%02d:%02d', intdiv($secondsAfterStart, 60), $secondsAfterStart % 60);
    }

    // ----- race control, pit log, connectivity -----

    public function ocorrencia(string $tipo, string $at, ?int $car = null, ?int $replaces = null): int
    {
        $id = $this->nextOcorrenciaId++;
        $this->ocorrencias[] = new Ocorrencia($id, $tipo, self::ms($at), $car, $replaces);
        return $id;
    }

    public function pitIn(int $car, string $at): int
    {
        return $this->ocorrencia(Ocorrencia::BOX_ENTRADA, $at, $car);
    }

    public function pitOut(int $car, string $at): int
    {
        return $this->ocorrencia(Ocorrencia::BOX_SAIDA, $at, $car);
    }

    /** The fueling station log: the car went in. */
    public function fuelIn(int $car, string $at): int
    {
        return $this->ocorrencia(Ocorrencia::ABASTECIMENTO_ENTRADA, $at, $car);
    }

    /** The fueling station log: the car came out. */
    public function fuelOut(int $car, string $at): int
    {
        return $this->ocorrencia(Ocorrencia::ABASTECIMENTO_SAIDA, $at, $car);
    }

    /**
     * A reviewer says the car must have left an area ("marcar saída do
     * box"): an exit nobody logged at the gate, inferred from a pass.
     *
     * @param string $area 'box' or 'abastecimento'
     */
    public function inferredExit(string $area, int $car, int $tsMs): int
    {
        $id = $this->nextOcorrenciaId++;
        $exit = new Ocorrencia($id, $area === 'box' ? Ocorrencia::BOX_SAIDA : Ocorrencia::ABASTECIMENTO_SAIDA, $tsMs, $car);
        $exit->inferida = true;
        $this->ocorrencias[] = $exit;
        return $id;
    }

    /** A pit lane or fueling event from a phone that had no usable time: the time it carries is the phone's own clock. */
    public function stopEventOfUnknownTime(string $tipo, int $car, string $at): int
    {
        $id = $this->ocorrencia($tipo, $at, $car);
        $this->ocorrencias[count($this->ocorrencias) - 1]->qualidadeTempo = Entry::TEMPO_DESCONHECIDO;
        return $id;
    }

    public function redFlag(string $at): int
    {
        return $this->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, $at);
    }

    public function restart(string $at): int
    {
        return $this->ocorrencia(Ocorrencia::RELARGADA, $at);
    }

    /**
     * Race control presses "cancelar" on an event (or "restaurar" on a
     * cancellation, which is cancelling the cancellation). Nothing is
     * deleted: a new row says the other one no longer holds.
     */
    public function cancel(int $ocorrenciaId): int
    {
        return $this->ocorrencia(Ocorrencia::CANCELAMENTO, '00:00', null, $ocorrenciaId);
    }

    /** Race control presses "corrigir" on an event: the same event at another time. */
    public function correct(int $ocorrenciaId, string $newTime): int
    {
        foreach ($this->ocorrencias as $ocorrencia) {
            if ($ocorrencia->id === $ocorrenciaId) {
                return $this->ocorrencia($ocorrencia->tipo, $newTime, $ocorrencia->carro, $ocorrenciaId);
            }
        }
        throw new \LogicException('There is no event ' . $ocorrenciaId . ' to correct.');
    }

    /** Race control ends the race. */
    public function finish(string $at): int
    {
        return $this->ocorrencia(Ocorrencia::ENCERRAMENTO, $at);
    }

    /**
     * The controller switches a counter off on the settings page at $at.
     * With null the counter is switched off and never took part at all.
     */
    public function deactivateSource(int $source, ?string $at): void
    {
        $this->sources[$source]->ativa = false;
        $this->sources[$source]->desativadaEmMs = $at === null ? null : self::ms($at);
    }

    /** No device is open on this counter any more: nothing can still arrive from it. */
    public function noDeviceOpen(int $source): void
    {
        $this->sources[$source]->sincronizadoAteMs = Source::NOTHING_TO_WAIT_FOR;
    }

    /**
     * Says how far a counter's device has uploaded: "everything it recorded
     * up to this moment is on the server". Null means it never connected.
     */
    public function syncedThrough(int $source, ?string $at): void
    {
        $this->sources[$source]->sincronizadoAteMs = $at === null ? null : self::ms($at);
    }

    /** Says when a counter's device was last heard from. */
    public function lastSeen(int $source, ?string $at): void
    {
        $this->sources[$source]->vistoEmMs = $at === null ? null : self::ms($at);
        $this->lastSeenSetByTest[$source] = true;
    }

    /** @param int[] $cars the car numbers of the teams present at the event (and classified, when the prova asks for that) */
    public function teamsPresent(array $cars): void
    {
        $this->validCars = $cars;
    }

    /**
     * @param int[] $cars teams that are present at the event but NOT classified
     *   (the prova names a classification field and theirs does not say "SIM")
     */
    public function teamsNotClassified(array $cars): void
    {
        $this->unclassifiedCars = $cars;
    }

    /** @param array<int, int> $lapsByCar a paper count typed in after the race */
    public function importTotals(int $source, array $lapsByCar): void
    {
        $this->totals[$source] = $lapsByCar;
    }

    /** Runs the engine as if the current time were $now on the race clock. */
    public function run(string $now): EngineResult
    {
        return Engine::run($this->input($now));
    }

    /**
     * The fast path an upload uses: recount only the given cars.
     *
     * @param int[] $cars
     */
    public function countCars(string $now, array $cars): CountResult
    {
        return Engine::countCars($this->input($now), $cars);
    }

    public function input(string $now): EngineInput
    {
        $nowMs = self::ms($now);
        foreach ($this->sources as $id => $source) {
            if (!isset($this->lastSeenSetByTest[$id])) {
                // An online counter's device calls the server every 5 seconds.
                $source->vistoEmMs = $nowMs;
            }
        }

        $input = new EngineInput($this->settings);
        $input->sources = array_values($this->sources);
        $input->entries = $this->entries;
        $input->decisions = $this->decisions;
        $input->ocorrencias = $this->ocorrencias;
        $input->totals = $this->totals;
        $input->validCars = $this->validCars;
        $input->unclassifiedCars = $this->unclassifiedCars;
        $input->nowMs = $nowMs;
        return $input;
    }

    /** @return int[] */
    private function countingSourceIds(): array
    {
        $ids = [];
        foreach ($this->sources as $source) {
            if ($source->countsPasses()) {
                $ids[] = $source->id;
            }
        }
        return $ids;
    }
}
