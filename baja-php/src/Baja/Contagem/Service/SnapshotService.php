<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Model\Prova;

/**
 * The slow path: the whole-race recalculation and the snapshot it leaves.
 *
 * Flags, lap times, the pace of the race, best laps and source statistics
 * all need the full picture: every entry of every car. That costs around a
 * tenth of a second for a real race, far too much to do on every upload.
 * So it is done here, under four rules:
 *
 * 1. At most once every `recalculo_s` seconds.
 * 2. Only when something changed since the last time (see ChangeCounter).
 * 3. One at a time. Whoever arrives while another request is calculating
 *    does NOT wait: it is handed the previous snapshot.
 * 4. Nobody is forced to wait for it, except after a decision: a reviewer
 *    who has just decided something must see the result of that decision.
 *
 * Any request may be the one that triggers it: an upload, a reviewer's
 * poll, a decision. The public feed never does.
 */
final class SnapshotService
{
    private Prova $prova;
    private ContagemService $service;
    private SnapshotStore $store;
    private ChangeCounter $changes;
    private ProvaLock $recalculationLock;

    public function __construct(Prova $prova, ContagemService $service, ChangeCounter $changes)
    {
        $this->prova = $prova;
        $this->service = $service;
        $this->store = new SnapshotStore($prova);
        $this->changes = $changes;
        $this->recalculationLock = new ProvaLock($prova, 'recalculo');
    }

    /**
     * The snapshot to serve right now. Recalculates first when the stored
     * one is stale and nobody else is already doing it.
     */
    public function current(): RaceSnapshot
    {
        $stored = $this->store->get();
        if ($stored !== null && !$this->isStale($stored)) {
            return $stored;
        }

        $fresh = null;
        $ran = $this->recalculationLock->runIfFree(function () use (&$fresh): void {
            $fresh = $this->recalculate();
        });
        if ($ran) {
            return $fresh;
        }
        if ($stored !== null) {
            // Somebody else is calculating. Their result will be here for
            // the next poll; this one gets what there is.
            return $stored;
        }

        // The very first snapshot of a race is being made by another
        // request. There is nothing older to serve, so wait for it.
        $this->recalculationLock->run(fn() => null);
        return $this->store->get() ?? $this->fresh();
    }

    /** Recalculates now, waiting for a recalculation already running to finish first. */
    public function fresh(): RaceSnapshot
    {
        return $this->recalculationLock->run(fn() => $this->recalculate());
    }

    /** Recalculates if the stored snapshot is stale and nobody else is at it. Never waits. */
    public function refreshIfStale(): void
    {
        $stored = $this->store->get();
        if ($stored !== null && !$this->isStale($stored)) {
            return;
        }
        $this->recalculationLock->runIfFree(function (): void {
            $this->recalculate();
        });
    }

    /**
     * Stores the snapshot of a full run somebody already made (after a
     * decision or a race-control action, which run the engine themselves
     * while holding the count lock).
     */
    public function storeFromRun(EngineInput $input, EngineResult $result, ?int $changesSeen): RaceSnapshot
    {
        $snapshot = new RaceSnapshot(
            bin2hex(random_bytes(8)),
            $input->nowMs,
            $changesSeen,
            SnapshotBuilder::build($this->service, $input, $result)
        );
        $this->store->put($snapshot);
        return $snapshot;
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    private function isStale(RaceSnapshot $snapshot): bool
    {
        $settings = $this->service->getSettings();
        $ageMs = Clock::nowMs() - $snapshot->geradoEmMs;
        if ($ageMs < $settings->recalculoS * 1000) {
            return false;
        }
        $changesNow = $this->changes->current();
        // Unknown (Redis down or restarted) counts as changed: one
        // recalculation too many, never one too few.
        return $changesNow === null || $changesNow !== $snapshot->mudancas;
    }

    /** One full run. The caller holds the recalculation lock. */
    private function recalculate(): RaceSnapshot
    {
        // Read BEFORE loading: a change that arrives while we calculate
        // leaves the counter ahead of this snapshot, so the next request
        // sees it as stale. Read after, that change could be missed.
        $changesSeen = $this->changes->current();
        $settings = $this->service->getSettings();

        $input = $this->service->loadInput();
        $result = Engine::run($input);
        $snapshot = $this->storeFromRun($input, $result, $changesSeen);

        $this->service->settleTimingResults($result, $settings);
        return $snapshot;
    }
}
