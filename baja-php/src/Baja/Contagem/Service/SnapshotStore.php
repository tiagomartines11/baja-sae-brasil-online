<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Model\Prova;

/**
 * Where the latest RaceSnapshot of a prova is kept.
 *
 * Redis first, because every PHP worker shares it and reading it costs
 * almost nothing. A copy is also written to a local file, so that a Redis
 * restart or outage in the middle of a race does not leave the review
 * screen and the public feed empty: they go on serving the last snapshot,
 * and the next recalculation writes a fresh one to whatever is available.
 */
final class SnapshotStore
{
    private string $redisKey;
    private string $filePath;

    public function __construct(Prova $prova)
    {
        $name = $prova->getEventoId() . '-' . $prova->getProvaId();
        $this->redisKey = 'contagem:' . $prova->getEventoId() . ':' . $prova->getProvaId() . ':snapshot';
        // Only letters and digits reach the file name, whatever the ids hold.
        // The system user is part of the name because a file written by the
        // web server's user cannot be replaced by a command-line script run
        // as somebody else (and the other way round); each would then keep
        // reading the other's old copy without any error.
        $systemUser = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'u';
        $this->filePath = sys_get_temp_dir() . '/baja-contagem-snapshot-' . $systemUser . '-'
            . preg_replace('/[^A-Za-z0-9-]/', '_', $name) . '.json';
    }

    public function get(): ?RaceSnapshot
    {
        $redis = RedisClient::get();
        if ($redis !== null) {
            try {
                $json = $redis->get($this->redisKey);
                if (is_string($json) && $json !== '') {
                    $snapshot = RaceSnapshot::fromJson($json);
                    if ($snapshot !== null) {
                        return $snapshot;
                    }
                }
            } catch (\Throwable $error) {
                error_log('Contagem\SnapshotStore: Redis read failed: ' . $error->getMessage());
            }
        }

        $json = @file_get_contents($this->filePath);
        return is_string($json) && $json !== '' ? RaceSnapshot::fromJson($json) : null;
    }

    public function put(RaceSnapshot $snapshot): void
    {
        $json = $snapshot->toJson();

        $redis = RedisClient::get();
        if ($redis !== null) {
            try {
                $redis->set($this->redisKey, $json);
            } catch (\Throwable $error) {
                error_log('Contagem\SnapshotStore: Redis write failed: ' . $error->getMessage());
            }
        }

        // Written under another name and renamed, so a reader never sees a
        // half-written file.
        $temporaryPath = $this->filePath . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporaryPath, $json) !== false) {
            @rename($temporaryPath, $this->filePath);
        }
    }

    public function clear(): void
    {
        $redis = RedisClient::get();
        if ($redis !== null) {
            try {
                $redis->del($this->redisKey);
            } catch (\Throwable $error) {
                error_log('Contagem\SnapshotStore: Redis delete failed: ' . $error->getMessage());
            }
        }
        @unlink($this->filePath);
    }
}
