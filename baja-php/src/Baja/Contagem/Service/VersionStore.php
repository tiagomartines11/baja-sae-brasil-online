<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Model\Prova;

/**
 * The small pieces of state the review screen polls for, kept in Redis:
 *
 *   contagem:{evento}:{prova}:versao           counter; "did anything change?"
 *   contagem:{evento}:{prova}:publicado_em_ms  when scores were last published
 *   contagem:{evento}:{prova}:impressao        fingerprint of the last engine result
 *
 * None of these keys has an expiry. Redis here runs with volatile-lru, which
 * only ever evicts keys that have one, so they survive memory pressure.
 *
 * When Redis is down the version falls back to the current time in seconds:
 * every poll then looks "changed" and the screen simply reloads its state
 * more often than it needs to, which is the safe direction to fail in.
 */
final class VersionStore
{
    private string $keyPrefix;

    public function __construct(Prova $prova)
    {
        $this->keyPrefix = 'contagem:' . $prova->getEventoId() . ':' . $prova->getProvaId() . ':';
    }

    public function current(): int
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return self::fallbackVersion();
        }
        try {
            // A key that does not exist yet reads as version 0, which is
            // what the first bump() will count up from.
            return (int) $redis->get($this->keyPrefix . 'versao');
        } catch (\Throwable $error) {
            error_log('Contagem\VersionStore: read failed: ' . $error->getMessage());
            return self::fallbackVersion();
        }
    }

    public function bump(): int
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return self::fallbackVersion();
        }
        try {
            return (int) $redis->incr($this->keyPrefix . 'versao');
        } catch (\Throwable $error) {
            error_log('Contagem\VersionStore: bump failed: ' . $error->getMessage());
            return self::fallbackVersion();
        }
    }

    public function lastPublishedAtMs(): ?int
    {
        $value = $this->read('publicado_em_ms');
        return $value === null ? null : (int) $value;
    }

    public function markPublished(int $nowMs): void
    {
        $this->write('publicado_em_ms', (string) $nowMs);
    }

    /**
     * Remembers a fingerprint of the engine result and says whether it
     * differs from the previous one. Unknown (Redis down, first run) counts
     * as "differs".
     */
    public function resultChanged(string $fingerprint): bool
    {
        $previous = $this->read('impressao');
        if ($previous === $fingerprint) {
            return false;
        }
        $this->write('impressao', $fingerprint);
        return true;
    }

    private function read(string $name): ?string
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return null;
        }
        try {
            $value = $redis->get($this->keyPrefix . $name);
        } catch (\Throwable $error) {
            error_log('Contagem\VersionStore: read failed: ' . $error->getMessage());
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function write(string $name, string $value): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->set($this->keyPrefix . $name, $value);
        } catch (\Throwable $error) {
            error_log('Contagem\VersionStore: write failed: ' . $error->getMessage());
        }
    }

    private static function fallbackVersion(): int
    {
        return intdiv(Clock::nowMs(), 1000);
    }
}
