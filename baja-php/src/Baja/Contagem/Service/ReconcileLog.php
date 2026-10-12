<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * The recent corrections made by the check on the fast path, kept where the
 * review screen can show them.
 *
 * When the whole-race recalculation finds that a published lap count
 * differs from what it computed, it corrects it (ScorePublisher::
 * reconcile()). That should never happen, and when it does the people
 * running the race must hear about it: a line in the PHP error log reaches
 * nobody on race day. So each correction is also remembered here for a day
 * and shown on the review screen.
 *
 * Kept in Redis: it is a notice, not a record. The error log stays the
 * permanent trace.
 */
final class ReconcileLog
{
    private const KEPT = 20;
    private const KEPT_FOR_SECONDS = 86400;

    private string $key;

    public function __construct(string $eventoId, string $provaId)
    {
        $this->key = 'contagem:' . $eventoId . ':' . $provaId . ':correcoes';
    }

    /**
     * @param array<int, array{carro: int, campo: string, publicado: mixed, calculado: int}> $corrections
     */
    public function add(array $corrections, int $nowMs): void
    {
        $redis = RedisClient::get();
        if ($redis === null || $corrections === []) {
            return;
        }
        try {
            foreach ($corrections as $correction) {
                $redis->lPush($this->key, (string) json_encode($correction + ['quando_ms' => $nowMs]));
            }
            $redis->lTrim($this->key, 0, self::KEPT - 1);
            $redis->expire($this->key, self::KEPT_FOR_SECONDS);
        } catch (\Throwable $error) {
            error_log('Contagem\ReconcileLog: write failed: ' . $error->getMessage());
        }
    }

    /**
     * @return array<int, array{carro: int, campo: string, publicado: mixed, calculado: int, quando_ms: int}> newest first
     */
    public function recent(): array
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return [];
        }
        try {
            $stored = $redis->lRange($this->key, 0, self::KEPT - 1);
        } catch (\Throwable $error) {
            return [];
        }
        $corrections = [];
        foreach (is_array($stored) ? $stored : [] as $json) {
            $correction = json_decode((string) $json, true);
            if (is_array($correction)) {
                $corrections[] = $correction;
            }
        }
        return $corrections;
    }

    public function clear(): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->del($this->key);
        } catch (\Throwable $error) {
            error_log('Contagem\ReconcileLog: delete failed: ' . $error->getMessage());
        }
    }
}
