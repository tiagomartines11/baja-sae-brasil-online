<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Contagem\Engine\Settings;
use Baja\Model\ContagemDecisao;
use Baja\Model\ContagemOcorrencia;
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
 * and recalculates it only when it is stale; see SnapshotService.
 *
 * A change made by a person (a decision, a race-control button, a setting)
 * can touch any car and must be visible to that person at once, so it does
 * both in one go: recomputeAndPublish().
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
    private ?PublishOutcome $lastPublishOutcome = null;
    private ?RaceSnapshot $lastSnapshot = null;

    private function __construct(Prova $prova)
    {
        $this->prova = $prova;
        $this->loader = new EngineInputLoader($prova);
        $this->lapStore = new LapStore($prova);
        $this->countLock = new ProvaLock($prova);
        $this->changes = new ChangeCounter((string) $prova->getEventoId(), (string) $prova->getProvaId());
        $this->snapshots = new SnapshotService($prova, $this, $this->changes);
        $this->context = new RaceContext((string) $prova->getEventoId(), (string) $prova->getProvaId());
    }

    public static function forProva(Prova $prova): self
    {
        return new self($prova);
    }

    public function getProva(): Prova
    {
        return $this->prova;
    }

    public function getSettings(): Settings
    {
        return Settings::fromArray($this->prova->getParams()->contagem ?? null);
    }

    /**
     * Stores the settings in prova.params.contagem, leaving the rest of
     * params alone, and applies them: a setting can change which passes
     * count, so the whole race is recounted and republished.
     */
    public function saveSettings(Settings $settings): void
    {
        $this->withLock(function () use ($settings): void {
            $params = $this->prova->getParams() ?? new \stdClass();
            $params->contagem = (object) $settings->toArray();
            $this->prova->setParams($params);
            $this->prova->save();
            $this->context->forget();
            $this->recomputeAndPublish();
        });
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
    // The fast path
    // ------------------------------------------------------------------

    /**
     * Recounts the given cars from their own entries, updates their laps
     * and publishes their VOLTAS and LAST. The caller must hold the lock.
     *
     * @param int[] $cars
     */
    public function recountCars(array $cars): PublishOutcome
    {
        $cars = array_values(array_unique(array_map('intval', $cars)));
        if ($cars === []) {
            return new PublishOutcome();
        }
        $settings = $this->getSettings();
        $count = Engine::countCars($this->loader->loadForCars($settings, $cars), $cars);

        $this->lapStore->replaceCars($count->lapsByCar);
        $outcome = (new ScorePublisher($this->prova, $settings))->publishCounts($count, $cars);
        $this->notePublish($outcome);
        $this->changes->markChanged();
        return $outcome;
    }

    // ------------------------------------------------------------------
    // The slow path
    // ------------------------------------------------------------------

    /** The latest whole-race snapshot, recalculated first only if stale and nobody else is at it. */
    public function snapshot(): RaceSnapshot
    {
        return $this->snapshots->current();
    }

    /** Recalculates the snapshot if it is stale. Never waits. For use after an answer was sent. */
    public function refreshSnapshotIfStale(): void
    {
        $this->snapshots->refreshIfStale();
    }

    /**
     * Called by the slow path after a full run: fills in the lap timing
     * columns and publishes BEST where it changed.
     *
     * The count lock is taken only for the publishing, and only briefly:
     * recalculating points must not interleave with an upload doing the
     * same. If an upload holds it, BEST waits for the next recalculation.
     */
    public function settleTimingResults(EngineResult $result, Settings $settings): void
    {
        $this->lapStore->updateTimingColumns($result->laps);

        $bestSecondsByCar = [];
        foreach ($result->cars as $carro => $state) {
            if ($state->voltas > 0) {
                $bestSecondsByCar[$carro] = PublishedValues::bestLapSeconds($state->melhorVoltaMs);
            }
        }
        try {
            $this->withLock(function () use ($bestSecondsByCar, $result, $settings): void {
                $outcome = (new ScorePublisher($this->prova, $settings))->publishBest($bestSecondsByCar, $result->timeline);
                $this->notePublish($outcome);
            }, ProvaLock::WAIT_FOR_AN_UPLOAD_SECONDS);
        } catch (LockBusy $busy) {
            // Counts as a change, so the next recalculation tries again.
            $this->changes->markChanged();
        }
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
    public function countCarsOnly(array $cars): \Baja\Contagem\Engine\Data\CountResult
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
        $changesSeen = $this->changes->current();
        $input = $this->loadInput();
        $result = Engine::run($input);

        $this->lapStore->replaceAll($result->laps);
        $this->notePublish((new ScorePublisher($this->prova, $input->settings))->publishAll($result));
        $this->context->forget();

        // The change count is raised first and the snapshot stored with the
        // new value, so the snapshot counts as up to date.
        $this->changes->markChanged();
        $this->lastSnapshot = $this->snapshots->storeFromRun($input, $result, $changesSeen === null ? null : $changesSeen + 1);
        return $result;
    }

    /** The snapshot stored by the last recomputeAndPublish() of this object, if any. */
    public function lastStoredSnapshot(): ?RaceSnapshot
    {
        return $this->lastSnapshot;
    }

    /** "Republicar tudo": rewrites every car's VOLTAS/LAST/BEST and recalculates points. */
    public function republishAll(): void
    {
        $this->withLock(function (): void {
            $input = $this->loadInput();
            $outcome = (new ScorePublisher($this->prova, $input->settings))->publishAll(Engine::run($input), true);
            $this->notePublish($outcome, true);
            $this->changes->markChanged();
        });
    }

    /** "Reconstruir": throws contagem_volta away and writes it again from the raw data. */
    public function rebuildLaps(): void
    {
        $this->withLock(function (): void {
            $this->recomputeAndPublish();
        });
    }

    /**
     * "Zerar corrida": archives and deletes the raw data of this prova
     * (entries, decisions, race-control and pit events, imported totals),
     * sets the published counts back to zero and clears the start time.
     * Sources, counter links and devices are kept.
     */
    public function resetRace(string $usuario): void
    {
        $this->withLock(function () use ($usuario): void {
            (new RaceReset($this->prova))->archiveAndDeleteRawData($usuario);
            $this->lapStore->deleteAll();

            $settings = $this->getSettings();
            $this->notePublish((new ScorePublisher($this->prova, $settings))->publishZeros(), true);
            if ($settings->publishesToScoring()) {
                $this->prova->setTempo(null);
                $this->prova->save();
            }
            $this->context->forget();
            $this->snapshots->clear();
            $this->changes->markChanged();
        });
    }

    /**
     * Stores a race-control event and reruns everything. The caller must
     * hold the lock.
     *
     * The start time of the race is also kept in prova.tempo, in Unix
     * seconds: the public results pages and the overlay take their race
     * clock from that column. It follows whatever start is in force after
     * this event (a start can be moved, or cancelled).
     *
     * @param string $tipo one of Ocorrencia::TIPOS
     * @param int|null $substituiId the earlier event this one corrects or cancels, if any
     */
    public function recordOcorrencia(string $tipo, int $tsMs, ?int $carro, string $usuario, ?int $substituiId = null): void
    {
        if (!in_array($tipo, Ocorrencia::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de ocorrência desconhecido: ' . $tipo);
        }

        $row = new ContagemOcorrencia();
        $row->setEventoId($this->prova->getEventoId());
        $row->setProvaId($this->prova->getProvaId());
        $row->setTipo($tipo);
        $row->setEquipeNumero($carro);
        $row->setTsMs($tsMs);
        $row->setUsuario($usuario);
        $row->setCriadoEmMs(Clock::nowMs());
        $row->setSubstituiId($substituiId);
        $row->save();

        $result = $this->recomputeAndPublish();

        if ($this->getSettings()->publishesToScoring()) {
            $startMs = $result->timeline->largadaMs;
            $this->prova->setTempo($startMs === null ? null : intdiv($startMs, 1000));
            $this->prova->save();
        }
    }

    /**
     * Stores a correction and returns its id. Does not rerun the engine:
     * the caller does that (recomputeAndPublish()) while still holding the
     * lock, so it can answer with the state the decision produced.
     */
    public function storeDecision(Decision $decision, string $usuario, ?string $motivo): int
    {
        $row = new ContagemDecisao();
        $row->setEventoId($this->prova->getEventoId());
        $row->setProvaId($this->prova->getProvaId());
        $row->setTipo($decision->tipo);
        $row->setPassagemId($decision->passagemId);
        $row->setFonteId($decision->fonteId);
        $row->setEquipeNumero($decision->carro);
        $row->setTsMs($decision->tsMs);
        $row->setTsFimMs($decision->tsFimMs);
        $row->setDesfazDecisaoId($decision->desfazDecisaoId);
        $row->setOrigem($decision->origem);
        $row->setUsuario($usuario);
        $row->setMotivo($motivo);
        $row->setCriadoEmMs(Clock::nowMs());
        $row->save();

        return (int) $row->getDecisaoId();
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

    /** Tells the cached race context and the slow path that something changed. */
    public function markChanged(bool $raceContextToo = false): void
    {
        if ($raceContextToo) {
            $this->context->forget();
        }
        $this->changes->markChanged();
    }

    /** When scores were last written to the scoring system; null if never (or unknown). */
    public function lastPublishedAtMs(): ?int
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return null;
        }
        try {
            $value = $redis->get($this->publishedAtKey());
        } catch (\Throwable $error) {
            return null;
        }
        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    /** What the most recent publish through this object did; null if none ran yet. */
    public function lastPublishOutcome(): ?PublishOutcome
    {
        return $this->lastPublishOutcome;
    }

    private function notePublish(PublishOutcome $outcome, bool $always = false): void
    {
        $this->lastPublishOutcome = $outcome;
        if (!$always && !$outcome->anythingChanged()) {
            return;
        }
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->set($this->publishedAtKey(), (string) Clock::nowMs());
        } catch (\Throwable $error) {
            error_log('Contagem\ContagemService: could not record publish time: ' . $error->getMessage());
        }
    }

    private function publishedAtKey(): string
    {
        return 'contagem:' . $this->prova->getEventoId() . ':' . $this->prova->getProvaId() . ':publicado_em_ms';
    }
}
