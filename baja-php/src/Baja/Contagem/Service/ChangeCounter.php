<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * Counts how many times anything about a prova's race has changed: an entry
 * stored, a decision, a race-control event, a setting, a device reporting
 * that it has uploaded everything.
 *
 * The slow, whole-race recalculation uses it to answer one question cheaply:
 * "has anything changed since the snapshot I already have?" If not, there is
 * nothing to recalculate.
 *
 * The number lives in Redis and means nothing by itself; only "is it the
 * same as before?" matters. When Redis is down or was restarted the answer
 * becomes "unknown" (null), which callers treat as "assume it changed": a
 * recalculation more than needed, never one less.
 */
final class ChangeCounter
{
    private string $key;

    /**
     * Takes the two ids rather than a Prova object so that a heartbeat can
     * use it without loading the prova from the database.
     */
    public function __construct(string $eventoId, string $provaId)
    {
        $this->key = 'contagem:' . $eventoId . ':' . $provaId . ':mudancas';
    }

    public function markChanged(): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->incr($this->key);
        } catch (\Throwable $error) {
            error_log('Contagem\ChangeCounter: incr failed: ' . $error->getMessage());
        }
    }

    /** The current count, or null when it cannot be known. */
    public function current(): ?int
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return null;
        }
        try {
            $value = $redis->get($this->key);
        } catch (\Throwable $error) {
            error_log('Contagem\ChangeCounter: read failed: ' . $error->getMessage());
            return null;
        }
        // A key that does not exist yet reads as 0 changes.
        return $value === false ? 0 : (int) $value;
    }
}
