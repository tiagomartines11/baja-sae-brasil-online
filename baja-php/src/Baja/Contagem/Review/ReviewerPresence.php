<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\RedisClient;
use Baja\Model\Prova;
use Throwable;

/**
 * "X is looking at flag Y", so two reviewers do not work on the same flag
 * without knowing.
 *
 * Kept in Redis because it is worthless a few seconds later and must never
 * cost a database write per poll. It is a courtesy, not a lock: if Redis is
 * down every method quietly does nothing and the review screen keeps working
 * (the real protection against double decisions is DecisionValidator).
 */
final class ReviewerPresence
{
    /** A reviewer who has not polled for this long is no longer shown. */
    public const EXPIRY_SECONDS = 15;

    /**
     * Called by every poll of the review screen.
     *
     * @param string|null $flagId the flag the reviewer has open, or null when none
     */
    public static function mark(Prova $prova, string $username, ?string $flagId): void
    {
        try {
            $redis = RedisClient::get();
            if ($redis === null) {
                return;
            }
            $key = self::key($prova);
            $redis->hSet($key, $username, json_encode([
                'alerta' => $flagId,
                'ate_ms' => Clock::nowMs() + self::EXPIRY_SECONDS * 1000,
            ]));
            // The whole hash disappears on its own once nobody polls any more.
            $redis->expire($key, self::EXPIRY_SECONDS);
        } catch (Throwable $ignored) {
            return;
        }
    }

    /**
     * @return array<string, string[]> flagId => usernames looking at it right now
     */
    public static function current(Prova $prova): array
    {
        $byFlag = [];
        foreach (self::liveEntries($prova) as $username => $flagId) {
            if ($flagId !== null) {
                $byFlag[$flagId][] = $username;
            }
        }
        foreach ($byFlag as &$usernames) {
            sort($usernames);
        }
        unset($usernames);
        return $byFlag;
    }

    /** @return string[] every reviewer with the review screen open right now */
    public static function online(Prova $prova): array
    {
        $usernames = array_map('strval', array_keys(self::liveEntries($prova)));
        sort($usernames);
        return $usernames;
    }

    /**
     * @return array<string, ?string> username => flag id (null when looking at none)
     */
    private static function liveEntries(Prova $prova): array
    {
        try {
            $redis = RedisClient::get();
            if ($redis === null) {
                return [];
            }
            $stored = $redis->hGetAll(self::key($prova));
        } catch (Throwable $ignored) {
            return [];
        }
        if (!is_array($stored)) {
            return [];
        }

        $nowMs = Clock::nowMs();
        $live = [];
        foreach ($stored as $username => $json) {
            $entry = json_decode((string) $json, true);
            // One reviewer leaving does not expire the hash while another
            // keeps polling, so each entry carries its own deadline.
            if (!is_array($entry) || (int) ($entry['ate_ms'] ?? 0) < $nowMs) {
                continue;
            }
            $flagId = $entry['alerta'] ?? null;
            $live[(string) $username] = is_string($flagId) && $flagId !== '' ? $flagId : null;
        }
        return $live;
    }

    private static function key(Prova $prova): string
    {
        return 'contagem:' . $prova->getEventoId() . ':' . $prova->getProvaId() . ':revisores';
    }
}
