<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Model\Prova;

/**
 * What the slow path does with a whole-race result once it has one: fills
 * in the lap timing columns, publishes BEST, and checks the fast path.
 *
 * The slow path computes WITHOUT the count lock, so by the time it is done
 * the race may have moved on. Everything here is written with that in
 * mind:
 *
 *   - the count lock is taken only for the publishing, and only briefly;
 *     if an upload holds it, the work waits for the next recalculation;
 *   - the settings are read again once the lock is held (publishing may
 *     have been switched off meanwhile);
 *   - counts are only corrected when NOTHING has changed since the run
 *     loaded the race. Otherwise the fast path is ahead, and correcting
 *     from an older picture would take laps away again.
 */
final class FullRunSettlement
{
    private Prova $prova;
    private ContagemService $service;
    private LapStore $lapStore;
    private ChangeCounter $changes;
    private SnapshotService $snapshots;
    private PublishLog $publishLog;
    private ReconcileLog $reconcileLog;

    public function __construct(
        Prova $prova,
        ContagemService $service,
        LapStore $lapStore,
        ChangeCounter $changes,
        SnapshotService $snapshots,
        PublishLog $publishLog,
        ReconcileLog $reconcileLog
    ) {
        $this->prova = $prova;
        $this->service = $service;
        $this->lapStore = $lapStore;
        $this->changes = $changes;
        $this->snapshots = $snapshots;
        $this->publishLog = $publishLog;
        $this->reconcileLog = $reconcileLog;
    }

    /**
     * @param int|null $changesSeen the change count the full run was computed from
     */
    public function settle(EngineResult $result, ?int $changesSeen): void
    {
        $this->lapStore->updateTimingColumns($result->laps);

        try {
            $this->service->withLock(function () use ($result, $changesSeen): void {
                $this->prova->reload();
                $publisher = new ScorePublisher($this->prova, $this->service->getSettings());

                if ($changesSeen !== null && $this->changes->current() === $changesSeen) {
                    // Nothing has changed since this run loaded the race, so
                    // its counts are THE counts. Whatever the scoring system
                    // holds that differs is a mistake of the fast path: it
                    // is corrected here, logged, and shown to the reviewers,
                    // because it should never happen.
                    $outcome = $publisher->reconcile($result);
                    $this->reconcileLog->add($outcome->corrections, Clock::nowMs());
                    $this->publishLog->note($outcome);
                    return;
                }
                if ($this->snapshots->storedHasSeenMoreThan($changesSeen)) {
                    // A decision published a newer picture in the meantime.
                    return;
                }
                // The race moved on while we computed. Counts are left to
                // the fast path, which is ahead of us; a best lap is safe
                // to publish, since it only ever improves or is corrected
                // by the next run.
                $bestSecondsByCar = [];
                foreach ($result->cars as $carro => $state) {
                    $bestSecondsByCar[$carro] = PublishedValues::bestLapSeconds($state->melhorVoltaMs);
                }
                $this->publishLog->note($publisher->publishBest($bestSecondsByCar, $result->timeline));
            }, ProvaLock::WAIT_FOR_AN_UPLOAD_SECONDS);
        } catch (LockBusy $busy) {
            // Counts as a change, so the next recalculation tries again.
            $this->changes->markChanged();
        }
    }
}
