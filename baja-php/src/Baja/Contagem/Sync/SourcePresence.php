<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Service\RedisClient;

/**
 * Who is counting on a source right now, so that two people sharing a
 * source during a handover each see that the other is there.
 *
 * Kept in Redis, because it is written and read on every heartbeat and
 * nothing depends on it being right: if Redis is down the page simply shows
 * "ninguém", and counting is not affected in any way.
 */
final class SourcePresence
{
    /** A device heard from within this long is "counting right now". */
    private const PRESENCE_WINDOW_MS = 15000;

    /** The whole record of a source is dropped after this long without any heartbeat. */
    private const KEY_EXPIRY_SECONDS = 120;

    /**
     * Records that this device is here and returns the usernames of the
     * OTHER people on the same source.
     *
     * @return string[]
     */
    public static function noteAndListOthers(int $fonteId, int $dispositivoId, ?string $usuario, int $nowMs): array
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return [];
        }
        $key = 'contagem:presenca:' . $fonteId;
        try {
            if ($usuario !== null) {
                $redis->hSet($key, (string) $dispositivoId, $nowMs . "\t" . $usuario);
                $redis->expire($key, self::KEY_EXPIRY_SECONDS);
            }
            $devices = $redis->hGetAll($key);
        } catch (\Throwable $error) {
            error_log('Contagem\SourcePresence: ' . $error->getMessage());
            return [];
        }

        $others = [];
        foreach (is_array($devices) ? $devices : [] as $deviceId => $value) {
            [$seenAtMs, $name] = array_pad(explode("\t", (string) $value, 2), 2, '');
            if ((int) $deviceId === $dispositivoId || $nowMs - (int) $seenAtMs > self::PRESENCE_WINDOW_MS) {
                continue;
            }
            // The same person with two tabs open is not "someone else".
            if ($usuario !== null && strcasecmp($name, $usuario) === 0) {
                continue;
            }
            $others[$name] = true;
        }
        $names = array_keys($others);
        sort($names);
        return $names;
    }
}
