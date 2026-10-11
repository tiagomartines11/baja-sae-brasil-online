<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Service\RedisClient;
use Propel\Runtime\Propel;

/**
 * What every request from a device, with or without data, tells us about
 * the device itself, and the single UPDATE that records it:
 *
 *   visto_em_ms          it is alive (now)
 *   sincronizado_ate_ms  how far it has uploaded everything, when it says
 *                        its queue is empty
 *   fechado_em_ms        its counter is done; cleared when it comes back
 */
final class DeviceHeartbeat
{
    /**
     * The engine only learns something new from a source's sync mark when
     * the mark has moved a meaningful distance. Reporting every five-second
     * step would make the whole-race recalculation run for nothing.
     */
    private const SYNC_STEP_MS = 10000;

    /** How long a device's mark is remembered in the Redis mirror after its last heartbeat. */
    private const MIRROR_MEMORY_SECONDS = 600;

    /** @var array<string, mixed> the device as DeviceTokens::describeByToken() returned it */
    private array $device;
    private int $nowMs;

    /** The new "synced through" mark this request proposes; null when it proposes none. */
    private ?int $proposedMarkMs;
    private bool $closing;

    /**
     * @param array<string, mixed> $device
     */
    public function __construct(array $device, UploadContents $upload, int $nowMs)
    {
        $this->device = $device;
        $this->nowMs = $nowMs;

        // The mark only moves when the device says its queue is empty AND
        // can say what time it is on the server's clock. A page that has no
        // anchor yet may be holding entries it cannot stamp. The mark can
        // never be in the future: a device cannot have uploaded everything
        // up to a moment that has not happened.
        $this->proposedMarkMs = $upload->queueEmpty && $upload->nowMs !== null
            ? min($upload->nowMs, $nowMs)
            : null;

        // "I am done" is only believed from a device with nothing unsent.
        // Any other contact means it is (still, or again) counting.
        $this->closing = $upload->closing && $upload->queueEmpty;
    }

    /** True when this request closed the device. The page waits for this before saying "turno encerrado". */
    public function closedTheDevice(): bool
    {
        return $this->closing;
    }

    public function save(): void
    {
        // The mark is written RELATIVE to what is stored (GREATEST), not
        // from the value this request read earlier: two overlapping
        // requests of one phone could otherwise finish in the wrong order
        // and move the mark backwards.
        //
        // "AND revogado_em_ms IS NULL": if a controller revoked the device a
        // moment ago, its row is left exactly as the revocation found it.
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'UPDATE contagem_dispositivo
                SET visto_em_ms = GREATEST(COALESCE(visto_em_ms, 0), ?),
                    sincronizado_ate_ms = IF(? IS NULL, sincronizado_ate_ms, GREATEST(COALESCE(sincronizado_ate_ms, 0), ?)),
                    fechado_em_ms = ?
              WHERE dispositivo_id = ? AND revogado_em_ms IS NULL'
        );
        $statement->execute([
            $this->nowMs,
            $this->proposedMarkMs,
            $this->proposedMarkMs,
            $this->closing ? $this->nowMs : null,
            $this->device['dispositivo_id'],
        ]);
    }

    /**
     * True when this request changed something the engine looks at: the
     * device closed, or its SOURCE's sync mark (the earliest among the
     * source's devices) moved into a new ten-second step.
     *
     * The marks of a prova's devices are mirrored in Redis for this, so the
     * question costs no database query. Without Redis the device's own mark
     * is used, which only means a few more recalculations than necessary.
     */
    public function changedWhatTheEngineSees(): bool
    {
        if ($this->closing) {
            $this->forgetInMirror();
            return true;
        }
        if ($this->proposedMarkMs === null) {
            return false;
        }
        $previousMarkMs = $this->device['sincronizado_ate_ms'];
        $newMarkMs = max($this->proposedMarkMs, $previousMarkMs ?? 0);

        $redis = RedisClient::get();
        if ($redis === null) {
            return self::step($previousMarkMs) !== self::step($newMarkMs);
        }
        try {
            $key = 'contagem:marcas:' . $this->device['fonte_id'];
            $deviceField = (string) $this->device['dispositivo_id'];
            $marks = $this->recentMarks($redis->hGetAll($key));
            $sourceMarkBefore = $marks === [] ? null : min($marks);

            $marks[$deviceField] = $newMarkMs;
            $redis->hSet($key, $deviceField, $newMarkMs . ':' . $this->nowMs);
            $redis->expire($key, 2 * self::MIRROR_MEMORY_SECONDS);

            return self::step($sourceMarkBefore) !== self::step(min($marks));
        } catch (\Throwable $error) {
            error_log('Contagem\DeviceHeartbeat: ' . $error->getMessage());
            return self::step($previousMarkMs) !== self::step($newMarkMs);
        }
    }

    /** A closed device no longer holds its source's mark down. */
    private function forgetInMirror(): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->hDel('contagem:marcas:' . $this->device['fonte_id'], (string) $this->device['dispositivo_id']);
        } catch (\Throwable $error) {
            error_log('Contagem\DeviceHeartbeat: ' . $error->getMessage());
        }
    }

    /**
     * The marks in the mirror that belong to devices heard from recently.
     * A phone that went silent must stop holding its source's mark down
     * here too, as it does in the real rule (see Service\SourceLoader).
     *
     * @param mixed $stored what Redis returned: device id => "mark:when it was written"
     * @return array<string, int> device id => mark
     */
    private function recentMarks($stored): array
    {
        $marks = [];
        foreach (is_array($stored) ? $stored : [] as $deviceField => $value) {
            [$markMs, $writtenAtMs] = array_pad(explode(':', (string) $value, 2), 2, '0');
            if ($this->nowMs - (int) $writtenAtMs <= self::MIRROR_MEMORY_SECONDS * 1000) {
                $marks[(string) $deviceField] = (int) $markMs;
            }
        }
        return $marks;
    }

    private static function step(?int $markMs): ?int
    {
        return $markMs === null ? null : intdiv($markMs, self::SYNC_STEP_MS);
    }
}
