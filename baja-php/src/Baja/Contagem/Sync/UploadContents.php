<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

/**
 * One upload request, read into named fields. Anything missing or of the
 * wrong shape becomes an empty list, null or false, so the rest of the code
 * never has to ask "is this key there?".
 */
final class UploadContents
{
    /** @var array<int, mixed> */
    public array $entries;
    /** @var array<int, mixed> */
    public array $voids;
    /** @var array<int, mixed> */
    public array $pitEvents;

    /** The device's raw clock when it sent the request. */
    public ?int $deviceNowMs;
    /** The clock offset the device believes right now; null when it has no estimate. */
    public ?int $clockOffsetMs;
    /** The device says it holds nothing unsent. */
    public bool $queueEmpty;
    /** The device says its counter is done ("Encerrar meu turno", or the page was closed). */
    public bool $closing;

    /**
     * @param array<string, mixed> $request the decoded JSON body
     */
    public function __construct(array $request)
    {
        $this->entries = self::listOf($request['entries'] ?? null);
        $this->voids = self::listOf($request['voids'] ?? null);
        $this->pitEvents = self::listOf($request['pit_events'] ?? null);
        $this->deviceNowMs = UploadFields::intOrNull($request['device_now_ms'] ?? null);
        $this->clockOffsetMs = UploadFields::intOrNull($request['clock_offset_ms'] ?? null);
        $this->queueEmpty = ($request['queue_empty'] ?? false) === true;
        $this->closing = ($request['encerrando'] ?? false) === true;
    }

    /** False for a heartbeat: a request with nothing to store. */
    public function carriesData(): bool
    {
        return $this->entries !== [] || $this->voids !== [] || $this->pitEvents !== [];
    }

    /**
     * The clock offsets sent with the individual entries and pit events, in
     * order. Used to find the device's first estimate.
     *
     * @return array<int, int|null>
     */
    public function itemOffsetsMs(): array
    {
        $offsets = [];
        foreach (array_merge($this->entries, $this->pitEvents) as $item) {
            $offsets[] = is_array($item) ? UploadFields::intOrNull($item['clock_offset_ms'] ?? null) : null;
        }
        return $offsets;
    }

    /**
     * @param mixed $value
     * @return array<int, mixed> the value if it is a JSON list, otherwise an empty list
     */
    private static function listOf($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
