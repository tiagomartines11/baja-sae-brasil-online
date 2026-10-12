<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Review\StateSnapshot;
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
        $dados = SnapshotBuilder::build($this->service, $input, $result);
        $snapshot = new RaceSnapshot(self::tokenOf($dados), $input->nowMs, $changesSeen, $dados);

        // "Look at what is stored, then store" must be one step. Without
        // the count lock around it, a decision could store its snapshot
        // between our look and our write, and ours (older) would land on
        // top of it. Decisions already hold this lock when they get here.
        try {
            return $this->service->withLock(function () use ($snapshot, $input, $changesSeen): RaceSnapshot {
                // A slow recalculation that started BEFORE a decision can
                // finish AFTER the decision stored its own, newer snapshot.
                // Storing ours then would put the pre-decision race back on
                // every screen.
                $stored = $this->store->get();
                if ($this->hasSeenMore($stored, $changesSeen)) {
                    return $stored;
                }
                $this->store->put($snapshot, $input->settings->recalculoS * 1000);
                return $snapshot;
            }, ProvaLock::WAIT_FOR_AN_UPLOAD_SECONDS);
        } catch (LockBusy $busy) {
            // Not stored. It still answers the request that computed it,
            // and counts as a change so that the next request tries again.
            $this->changes->markChanged();
            return $snapshot;
        }
    }

    /**
     * The token is a digest of the content: recalculating a race in which
     * nothing a reviewer looks at changed gives the same token, and review
     * screens are told "inalterado" instead of downloading it all again.
     *
     * What changes with every heartbeat of every phone (when each device
     * was last seen, how far each has uploaded) is left out of the digest.
     * Those numbers are sent with every answer anyway, changed or not; with
     * them in the digest the token changed every few seconds and "nothing
     * new" was never said.
     *
     * @param array<string, mixed> $dados as built by SnapshotBuilder
     */
    private static function tokenOf(array $dados): string
    {
        $liveKeys = defined(StateSnapshot::class . '::LIVE_KEYS') ? constant(StateSnapshot::class . '::LIVE_KEYS') : [];
        foreach ($liveKeys as $key) {
            unset($dados['estado'][$key]);
        }
        unset($dados['tempo_real']['sinc_min_ms']);
        return sha1((string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** True when the stored snapshot was computed from a later state of the race than $changesSeen. */
    public function storedHasSeenMoreThan(?int $changesSeen): bool
    {
        return $this->hasSeenMore($this->store->get(), $changesSeen);
    }

    private function hasSeenMore(?RaceSnapshot $stored, ?int $changesSeen): bool
    {
        if ($stored === null || $stored->mudancas === null || $changesSeen === null) {
            return false;
        }
        // The change count only ever grows, so a stored snapshot that claims
        // more changes than the counter shows NOW was made before Redis
        // lost the counter (a restart). Its number means nothing any more,
        // and obeying it would keep that old snapshot on screen for hours.
        $changesNow = $this->changes->current();
        if ($changesNow !== null && $stored->mudancas > $changesNow) {
            return false;
        }
        return $stored->mudancas > $changesSeen;
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

        $input = $this->service->loadInput();
        $result = Engine::run($input);
        $snapshot = $this->storeFromRun($input, $result, $changesSeen);

        $this->service->settleFullRun($result, $changesSeen);
        return $snapshot;
    }
}
