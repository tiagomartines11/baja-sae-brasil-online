<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Engine;
use Baja\Model\Prova;

/**
 * Counting and publishing: running the engine, writing the lap rows and
 * the scores, in the two sizes the work comes in.
 *
 *   forCars()   the quick one, inside an upload: only the cars it touched
 *   wholeRace() after a change made by a person: every car, plus a fresh
 *               snapshot, so the answer to their request shows the effect
 *
 * and the three maintenance buttons of race control: "Republicar tudo",
 * "Reconstruir" and "Zerar corrida".
 *
 * Every method that writes expects the count lock to be held (the ones
 * behind a button take it themselves).
 */
final class Recount
{
    private Prova $prova;
    private ContagemService $service;
    private EngineInputLoader $loader;
    private LapStore $lapStore;
    private ChangeCounter $changes;
    private SnapshotService $snapshots;
    private RaceContext $context;
    private PublishLog $publishLog;
    private RaceRecords $records;
    private ?RaceSnapshot $lastSnapshot = null;

    public function __construct(
        Prova $prova,
        ContagemService $service,
        EngineInputLoader $loader,
        LapStore $lapStore,
        ChangeCounter $changes,
        SnapshotService $snapshots,
        RaceContext $context,
        PublishLog $publishLog,
        RaceRecords $records
    ) {
        $this->prova = $prova;
        $this->service = $service;
        $this->loader = $loader;
        $this->lapStore = $lapStore;
        $this->changes = $changes;
        $this->snapshots = $snapshots;
        $this->context = $context;
        $this->publishLog = $publishLog;
        $this->records = $records;
    }

    /**
     * THE FAST PATH. Recounts the given cars from their own entries,
     * updates their lap rows and publishes their VOLTAS and LAST. A few
     * milliseconds, however long the race is.
     *
     * A counter's "??" counts for the car the engine works out from what
     * the others read around it (UnreadAssigner), so an entry arriving near
     * one can change the count of a car it is not about: another source's
     * reading of car 7 is what lets Contagem 2's "??" count for car 7. With
     * $nearMs (the moments of what the upload touched), the cars read
     * around the "??" near those moments are recounted as well. Nothing is
     * added while the prova has no "??".
     *
     * @param int[] $cars
     * @param int[] $nearMs
     */
    public function forCars(array $cars, array $nearMs = []): PublishOutcome
    {
        $settings = $this->service->getSettings();
        if ($nearMs !== []) {
            $cars = array_merge($cars, $this->loader->carsNearUnread($settings, $nearMs));
        }
        $cars = array_values(array_unique(array_map('intval', $cars)));
        if ($cars === []) {
            return new PublishOutcome();
        }
        $count = Engine::countCars($this->loader->loadForCars($settings, $cars), $cars);

        $this->lapStore->replaceCars($count->lapsByCar);
        $outcome = (new ScorePublisher($this->prova, $settings))->publishCounts($count, $cars);
        $this->publishLog->note($outcome);
        $this->changes->markChanged();
        return $outcome;
    }

    /**
     * Runs the whole engine, rewrites the laps, publishes every car and
     * stores a fresh snapshot.
     */
    public function wholeRace(): EngineResult
    {
        $changesSeen = $this->changes->current();
        $input = $this->loader->load($this->service->getSettings());
        $result = Engine::run($input);

        // Only the rows that differ, count and timing columns in one go: a
        // decision usually touches one or two cars, and the lock is held
        // while this runs.
        $this->lapStore->replaceEveryCar($result->laps);
        $publisher = new ScorePublisher($this->prova, $input->settings);
        $outcome = $publisher->publishAll($result);
        if ($outcome->skippedNoStart && $this->records->aStartWasEverRecorded()) {
            // The race HAD a start and no longer has one (its only start
            // was cancelled). The counts on the results page are ours and
            // are now wrong, so they go back to zero. This is different
            // from a prova that never started here, whose numbers may be
            // imported totals and are left alone.
            $outcome = $publisher->publishZeros();
        }
        $this->publishLog->note($outcome);
        $this->context->refresh();

        // The change count is raised first and the snapshot stored with the
        // new value, so the snapshot counts as up to date.
        $this->changes->markChanged();
        $this->lastSnapshot = $this->snapshots->storeFromRun($input, $result, $changesSeen === null ? null : $changesSeen + 1);
        return $result;
    }

    /** The snapshot stored by the last wholeRace() of this object, if any. */
    public function lastStoredSnapshot(): ?RaceSnapshot
    {
        return $this->lastSnapshot;
    }

    /** "Republicar tudo": rewrites every car's VOLTAS/LAST/BEST and recalculates points. */
    public function republishAll(): void
    {
        $this->service->withLock(function (): void {
            $input = $this->loader->load($this->service->getSettings());
            $outcome = (new ScorePublisher($this->prova, $input->settings))->publishAll(Engine::run($input), true);
            $this->publishLog->note($outcome, true);
            $this->changes->markChanged();
        });
    }

    /** "Reconstruir": throws contagem_volta away and writes it again from the raw data. */
    public function rebuildLaps(): void
    {
        $this->service->withLock(function (): void {
            $this->lapStore->deleteAll();
            $this->wholeRace();
        });
    }

    /**
     * "Zerar corrida": archives and deletes the raw data of this prova
     * (entries, decisions, race-control and pit events, imported totals),
     * sets the published counts back to zero and clears the start time.
     * Sources, counter links and devices are kept.
     */
    public function resetRace(string $usuario, ReconcileLog $reconcileLog): void
    {
        $this->service->withLock(function () use ($usuario, $reconcileLog): void {
            (new RaceReset($this->prova))->archiveAndDeleteRawData($usuario);
            $this->lapStore->deleteAll();

            $settings = $this->service->getSettings();
            $this->publishLog->note((new ScorePublisher($this->prova, $settings))->publishZeros(), true);
            if ($settings->publishesToScoring()) {
                $this->prova->setTempo(null);
                $this->prova->save();
            }
            $this->context->refresh();
            $this->snapshots->clear();
            $reconcileLog->clear();
            $this->changes->markChanged();
        });
    }
}
