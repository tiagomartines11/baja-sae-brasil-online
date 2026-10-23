<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\CountResult;
use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Model\Prova;

/**
 * The lap counting of one prova, as the pages and endpoints see it.
 *
 * This class decides nothing itself. It strings the pieces together, along
 * the two paths the work is split into:
 *
 * THE FAST PATH: the official count. recountCars() recounts only the cars
 * an upload touched, updates their rows and publishes VOLTAS and LAST, all
 * inside the upload request and under the count lock. It costs a few
 * milliseconds however long the race is.
 *
 * THE SLOW PATH: everything that needs the whole race (flags, lap times,
 * pace, best laps, statistics). snapshot() serves the last result of that
 * and recalculates it only when it is stale; see SnapshotService and
 * FullRunSettlement.
 *
 * A change made by a person (a decision, a race-control button, a setting)
 * can touch any car and must be visible to that person at once, so it does
 * both in one go: recomputeAndPublish().
 *
 * WHERE THE REST IS
 *
 *   Recount            running the engine, writing laps and scores
 *   SettingsChanges    saving settings, the test preset, leaving a rehearsal
 *   RaceRecords        storing race-control events and decisions
 *   InferredExits      pit exits a reviewer marks
 *   FullRunSettlement  what the slow path publishes and checks
 *   PublishLog         when scores were last published; prova.tempo
 *   ReconcileLog       corrections the slow path made to the fast path
 */
final class ContagemService
{
    private Prova $prova;
    private EngineInputLoader $loader;
    private LapStore $lapStore;
    private ProvaLock $countLock;
    private ChangeCounter $changes;
    private SnapshotService $snapshots;
    private RaceContext $context;
    private PublishLog $publishLog;
    private ReconcileLog $reconcileLog;
    private RaceRecords $records;
    private SettingsChanges $settingsChanges;
    private Recount $recount;

    private function __construct(Prova $prova)
    {
        $eventoId = (string) $prova->getEventoId();
        $provaId = (string) $prova->getProvaId();

        $this->prova = $prova;
        $this->loader = new EngineInputLoader($prova);
        $this->lapStore = new LapStore($prova);
        $this->countLock = new ProvaLock($prova);
        $this->changes = new ChangeCounter($eventoId, $provaId);
        $this->snapshots = new SnapshotService($prova, $this, $this->changes);
        $this->context = new RaceContext($eventoId, $provaId);
        $this->publishLog = new PublishLog($prova);
        $this->reconcileLog = new ReconcileLog($eventoId, $provaId);
        $this->records = new RaceRecords($prova);
        $this->settingsChanges = new SettingsChanges($prova, $this, $this->publishLog);
        $this->recount = new Recount(
            $prova,
            $this,
            $this->loader,
            $this->lapStore,
            $this->changes,
            $this->snapshots,
            $this->context,
            $this->publishLog,
            $this->records
        );
    }

    public static function forProva(Prova $prova): self
    {
        return new self($prova);
    }

    public function getProva(): Prova
    {
        return $this->prova;
    }

    /**
     * Runs $work while holding the count lock and returns its result.
     * Safe to nest: code that already holds the lock may call it again.
     *
     * @return mixed
     * @throws LockBusy when the lock is not obtained within $waitSeconds
     */
    public function withLock(callable $work, int $waitSeconds = ProvaLock::WAIT_FOR_A_PERSON_SECONDS)
    {
        return $this->countLock->run($work, $waitSeconds);
    }

    // ------------------------------------------------------------------
    // Settings (see SettingsChanges)
    // ------------------------------------------------------------------

    public function getSettings(): Settings
    {
        return Settings::fromArray($this->prova->getParams()->contagem ?? null);
    }

    /**
     * Stores the settings and applies them: the whole race is recounted
     * and republished.
     *
     * @throws \InvalidArgumentException with a message in Portuguese when the change is refused
     */
    public function saveSettings(Settings $settings): void
    {
        $this->settingsChanges->save($settings);
    }

    /** "Predefinição de teste": time settings scaled to this average lap; simulation allowed. */
    public function applyTestPreset(int $voltaMediaS): void
    {
        $this->settingsChanges->applyTestPreset($voltaMediaS);
    }

    /**
     * Leaves the test preset: the time settings from before it come back,
     * simulation goes off, everything else stays.
     *
     * @return bool false when there was nothing to put back
     * @throws \InvalidArgumentException while simulated records exist (message for the person)
     * @throws PublishingWouldStart when this would start publishing and that was not confirmed
     */
    public function restoreSavedSettings(bool $confirmedPublishing = false): bool
    {
        return $this->settingsChanges->restore($confirmedPublishing);
    }

    /** True while the test preset is applied (settings from before it are kept, waiting to be restored). */
    public function hasSavedSettings(): bool
    {
        return $this->settingsChanges->presetIsApplied();
    }

    /** True when the prova holds entries or stop events made by the simulation. */
    public function hasSimulatedRows(): bool
    {
        return $this->settingsChanges->hasSimulatedRows();
    }

    // ------------------------------------------------------------------
    // The fast path
    // ------------------------------------------------------------------

    /**
     * Recounts the given cars from their own entries, updates their laps
     * and publishes their VOLTAS and LAST. The caller must hold the lock.
     *
     * @param int[] $cars
     * @param int[] $nearMs moments of the entries the change touched: the cars around a "??" or a timing sensor's mark near them are recounted too
     * @param bool $onlyMarks the change is a timing sensor's own (its marks are no reading of any car)
     * @param int[]|null $newEntryIds when the change only added entries: their ids (see Recount::forCars())
     */
    public function recountCars(array $cars, array $nearMs = [], bool $onlyMarks = false, ?array $newEntryIds = null): PublishOutcome
    {
        return $this->recount->forCars($cars, $nearMs, $onlyMarks, $newEntryIds);
    }

    // ------------------------------------------------------------------
    // The slow path
    // ------------------------------------------------------------------

    /** The latest whole-race snapshot, recalculated first only if stale and nobody else is at it. */
    public function snapshot(): RaceSnapshot
    {
        return $this->snapshots->current();
    }

    /** The snapshot as stored, however old, without ever recalculating. Null when there is none. */
    public function storedSnapshot(): ?RaceSnapshot
    {
        return $this->snapshots->stored();
    }

    /** Recalculates the snapshot if it is stale. Never waits. For use after an answer was sent. */
    public function refreshSnapshotIfStale(): void
    {
        $this->snapshots->refreshIfStale();
    }

    /**
     * Called by the slow path after a full run: fills in the lap timing
     * columns, publishes BEST, and checks the fast path's work. See
     * FullRunSettlement.
     *
     * @param int|null $changesSeen the change count the full run was computed from
     */
    public function settleFullRun(EngineResult $result, ?int $changesSeen): void
    {
        $settlement = new FullRunSettlement(
            $this->prova,
            $this,
            $this->lapStore,
            $this->changes,
            $this->snapshots,
            $this->publishLog,
            $this->reconcileLog
        );
        $settlement->settle($result, $changesSeen);
    }

    /**
     * The corrections the check on the fast path made in the last day,
     * newest first, for a visible warning on the review screen.
     *
     * @return array<int, array{carro: int, campo: string, publicado: mixed, calculado: int, quando_ms: int}>
     */
    public function lastReconcileCorrections(): array
    {
        return $this->reconcileLog->recent();
    }

    // ------------------------------------------------------------------
    // Whole-race work, for people
    // ------------------------------------------------------------------

    /** Loads the whole race. No writes. */
    public function loadInput(): EngineInput
    {
        return $this->loader->load($this->getSettings());
    }

    /**
     * The official passes of a few cars, with the pass groups behind them
     * (each Lap carries its group). Writes nothing. This is what a car's
     * timeline on the review screen needs, at a fraction of a full run.
     *
     * @param int[] $cars
     */
    public function countCarsOnly(array $cars): CountResult
    {
        $cars = array_values(array_unique(array_map('intval', $cars)));
        return Engine::countCars($this->loader->loadForCars($this->getSettings(), $cars), $cars);
    }

    /** Loads everything and runs the engine. Writes nothing. */
    public function compute(): EngineResult
    {
        return Engine::run($this->loadInput());
    }

    /**
     * After a change made by a person: runs the whole engine, rewrites the
     * laps, publishes every car and stores a fresh snapshot, so the person
     * sees the effect of what they did in the very answer to their request.
     * The caller must hold the lock.
     */
    public function recomputeAndPublish(): EngineResult
    {
        return $this->recount->wholeRace();
    }

    /** The snapshot stored by the last recomputeAndPublish() of this object, if any. */
    public function lastStoredSnapshot(): ?RaceSnapshot
    {
        return $this->recount->lastStoredSnapshot();
    }

    /** "Republicar tudo": rewrites every car's VOLTAS/LAST/BEST and recalculates points. */
    public function republishAll(): void
    {
        $this->recount->republishAll();
    }

    /** "Reconstruir": throws contagem_volta away and writes it again from the raw data. */
    public function rebuildLaps(): void
    {
        $this->recount->rebuildLaps();
    }

    /**
     * "Zerar corrida": archives and deletes the raw data of this prova,
     * sets the published counts back to zero and clears the start time.
     * Sources, counter links and devices are kept.
     */
    public function resetRace(string $usuario): void
    {
        $this->recount->resetRace($usuario, $this->reconcileLog);
    }

    // ------------------------------------------------------------------
    // Records people make (see RaceRecords, InferredExits)
    // ------------------------------------------------------------------

    /**
     * Stores a race-control event and reruns everything. The caller must
     * hold the lock. The race clock of the results pages (prova.tempo)
     * follows whatever start is in force after this event.
     *
     * @param string $tipo one of Ocorrencia::TIPOS
     * @param int|null $substituiId the earlier event this one corrects or cancels, if any
     */
    public function recordOcorrencia(string $tipo, int $tsMs, ?int $carro, string $usuario, ?int $substituiId = null): void
    {
        $this->records->storeOcorrencia($tipo, $tsMs, $carro, $usuario, $substituiId);
        $result = $this->recomputeAndPublish();
        if ($this->getSettings()->publishesToScoring()) {
            $this->publishLog->publishStartTime($result);
        }
    }

    /**
     * Stores a correction and returns its id. Does not rerun the engine:
     * the caller does that (recomputeAndPublish()) while still holding the
     * lock, so it can answer with the state the decision produced.
     */
    public function storeDecision(Decision $decision, string $usuario, ?string $motivo): int
    {
        return $this->records->storeDecision($decision, $usuario, $motivo);
    }

    /**
     * "Encerrar exclusão em h:mm:ss": replaces an open-ended exclusion of a
     * source by one with an end. The caller holds the lock and reruns the
     * engine afterwards.
     *
     * @return int the id of the new, bounded exclusion
     * @throws \InvalidArgumentException with a message in Portuguese, fit to show to the reviewer
     */
    public function closeOpenExclusion(int $decisaoId, int $tsFimMs, string $usuario, ?string $motivo): int
    {
        return $this->records->closeOpenExclusion($decisaoId, $tsFimMs, $usuario, $motivo);
    }

    /**
     * "Marcar saída do box" / "do abastecimento": records the exit nobody
     * logged, for a car that passed the line while logged inside an area.
     * The caller holds the lock and reruns the engine.
     *
     * @param string $area 'box' or 'abastecimento'
     * @return int the id of the new event
     */
    public function recordInferredExit(string $area, int $carro, int $tsMs, string $usuario): int
    {
        return InferredExits::record($this->prova, $area, $carro, $tsMs, $usuario);
    }

    /**
     * Undoing "marcar saída" from the decision history: cancels the exit
     * that decision recorded. The caller holds the lock and reruns the engine.
     *
     * @param int $decisaoId the `marcar_saida` decision being undone
     */
    public function cancelInferredExit(int $decisaoId, string $usuario): void
    {
        InferredExits::cancel($this->prova, $decisaoId, $usuario);
    }

    // ------------------------------------------------------------------
    // Which cars take part
    // ------------------------------------------------------------------

    /** Present cars, split into classified and not; see CarRoster. */
    public function carRoster(): CarRoster
    {
        return CarRoster::forProva($this->prova);
    }

    /**
     * Fills in the classification field where it is blank, and recounts:
     * passes those cars already had now count.
     *
     * Points are recalculated in the same locked step even when the recount
     * moved no published number: the field just written is a field of the
     * score row, and a formula may read it.
     *
     * Refused while the classification field is one the scoring formula
     * reads: writing SIM into DESCLAS is what scored every car 0 at 26SE.
     *
     * @return array{marcados: int[], ja_nao: int[]} the cars now marked, and those left alone because their field was not blank
     * @throws \InvalidArgumentException with a message in Portuguese when the field is one the formula reads
     */
    public function markAllPresentAsClassified(string $usuario): array
    {
        $conflict = ScoringFields::classificationConflict($this->prova);
        if ($conflict !== null) {
            throw new \InvalidArgumentException(ScoringFields::whyNot($conflict) . ' Nada foi alterado.');
        }
        return $this->withLock(function () use ($usuario): array {
            $outcome = ClassificationWriter::markAllPresent($this->prova, $usuario);
            if ($outcome['marcados'] !== []) {
                $this->recomputeAndPublish();
                $published = $this->publishLog->lastOutcome();
                if ($published === null || !$published->refreshRan) {
                    (new ScorePublisher($this->prova, $this->getSettings()))->refreshPointsNow();
                }
            }
            return $outcome;
        });
    }

    // ------------------------------------------------------------------
    // Small things other classes ask for
    // ------------------------------------------------------------------

    /**
     * Teams of the event keyed by car number, for names on screens.
     *
     * @return array<int, array{numero: int, nome: string, escola: string}>
     */
    public function loadTeams(bool $onlyPresent = true): array
    {
        return $this->loader->loadTeams($onlyPresent);
    }

    /**
     * Start, stoppages, cars in the pit lane, valid car numbers and the
     * matching window: what every count page answer carries. Cached.
     *
     * @return array<string, mixed>
     */
    public function raceContext(): array
    {
        return $this->context->get();
    }

    /**
     * Called when a whole-race count has just been stored. If it assumed
     * other pit exits than the cached race context knows of, the context is
     * rebuilt (it reads them from the snapshot just stored), so the pit-log
     * phones stop listing a car the count has out on the track. It is not a
     * change to the race: nothing needs counting again.
     *
     * @param array<int, array{area: string, carro: int, ts_ms: int, entrada_ms: int}> $assumedExits
     */
    public function tellRaceContextWhichExitsWereAssumed(array $assumedExits): void
    {
        $known = $this->context->get()['saidas_presumidas'] ?? [];
        if (json_encode($known) !== json_encode($assumedExits)) {
            $this->context->refresh();
        }
    }

    /** Tells the cached race context and the slow path that something changed. */
    public function markChanged(bool $raceContextToo = false): void
    {
        if ($raceContextToo) {
            $this->context->refresh();
        }
        $this->changes->markChanged();
    }

    /** When scores were last written to the scoring system; null if never (or unknown). */
    public function lastPublishedAtMs(): ?int
    {
        return $this->publishLog->lastPublishedAtMs();
    }

    /**
     * What the screens say about the points, beside "publicado há N s":
     *
     *   campo_classificacao_na_formula  the classification field, when the scoring formula reads it (else null)
     *   todos_os_pontos_zero            a car has a lap and every published points value is 0 or empty
     *   recalculados_em_ms              when points were last recalculated (prova.modificado), null if unknown
     *
     * @return array{campo_classificacao_na_formula: ?string, todos_os_pontos_zero: bool, recalculados_em_ms: ?int}
     */
    public function scoringCheck(): array
    {
        return [
            'campo_classificacao_na_formula' => ScoringFields::classificationConflict($this->prova),
            'todos_os_pontos_zero' => $this->publishLog->allPublishedPointsAreZero(),
            'recalculados_em_ms' => $this->publishLog->pointsRecalculatedAtMs(),
        ];
    }

    /** What the most recent publish through this object did; null if none ran yet. */
    public function lastPublishOutcome(): ?PublishOutcome
    {
        return $this->publishLog->lastOutcome();
    }
}
