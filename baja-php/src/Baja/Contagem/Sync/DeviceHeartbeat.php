<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Propel\Runtime\Propel;

/**
 * What every request from a device, with or without data, tells us about
 * the device itself, and the single UPDATE that records it:
 *
 *   visto_em_ms          it is alive (now)
 *   sincronizado_ate_ms  how far it has uploaded everything, when it says
 *                        its queue is empty
 *   primeiro_desvio_ms   its first clock offset estimate, set once
 *   fechado_em_ms        its counter is done; cleared when it comes back
 */
final class DeviceHeartbeat
{
    /** @var array<string, mixed> the device as DeviceTokens::describeByToken() returned it */
    private array $device;
    private int $nowMs;

    private ?int $firstOffsetMs;
    private ?int $syncedThroughMs;
    private ?int $closedAtMs;

    /**
     * @param array<string, mixed> $device
     */
    public function __construct(array $device, UploadContents $upload, int $nowMs)
    {
        $this->device = $device;
        $this->nowMs = $nowMs;

        $this->firstOffsetMs = ClockCorrection::firstOffset(
            $device['primeiro_desvio_ms'],
            $upload->clockOffsetMs,
            $upload->itemOffsetsMs()
        );
        $this->syncedThroughMs = ClockCorrection::syncedThrough(
            $device['sincronizado_ate_ms'],
            $upload->queueEmpty,
            $upload->deviceNowMs,
            $upload->clockOffsetMs ?? $this->firstOffsetMs,
            $nowMs
        );
        // "I am done" is only believed from a device with nothing unsent.
        // Any other contact means it is (still, or again) counting.
        $this->closedAtMs = $upload->closing && $upload->queueEmpty ? $nowMs : null;
    }

    /** The best clock offset known for the device: this request's, else its first. */
    public function bestKnownOffsetMs(): ?int
    {
        return $this->firstOffsetMs;
    }

    /**
     * True when this request changed something the engine looks at: how far
     * the device has uploaded, or whether it is still expected.
     */
    public function changedWhatIsKnown(): bool
    {
        return $this->syncedThroughMs !== $this->device['sincronizado_ate_ms'] || $this->closedAtMs !== null;
    }

    public function save(): void
    {
        // "AND revogado_em_ms IS NULL": if a controller revoked the device a
        // moment ago, its row is left exactly as the revocation found it.
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'UPDATE contagem_dispositivo
                SET visto_em_ms = ?, sincronizado_ate_ms = ?, primeiro_desvio_ms = ?, fechado_em_ms = ?
              WHERE dispositivo_id = ? AND revogado_em_ms IS NULL'
        );
        $statement->execute([
            $this->nowMs,
            $this->syncedThroughMs,
            $this->firstOffsetMs,
            $this->closedAtMs,
            $this->device['dispositivo_id'],
        ]);
    }
}
