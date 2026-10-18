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
    /**
     * A counter saying which car one of their own "??" was ("era o 17"):
     * items {uuid, car}. See EntryWriter::storeFixes().
     *
     * @var array<int, mixed>
     */
    public array $fixes;

    /**
     * The page's current time on the SERVER's clock, as it works it out
     * from its anchor. Null while the page has no anchor (it has not yet
     * had one successful answer since it was opened).
     */
    public ?int $nowMs;
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
        $this->fixes = self::listOf($request['fixes'] ?? null);
        $this->nowMs = UploadFields::intOrNull($request['now_ms'] ?? null);
        $this->queueEmpty = ($request['queue_empty'] ?? false) === true;
        $this->closing = ($request['encerrando'] ?? false) === true;
    }

    /** False for a heartbeat: a request with nothing to store. */
    public function carriesData(): bool
    {
        return $this->entries !== [] || $this->voids !== [] || $this->pitEvents !== [] || $this->fixes !== [];
    }

    /**
     * @param mixed $value
     * @return array<int, mixed> the value if it is a JSON list (its first elements, see
     *         UploadFields::MAX_ITEMS_PER_REQUEST), otherwise an empty list. What is cut off
     *         is not acknowledged, so the client sends it again.
     */
    private static function listOf($value): array
    {
        return UploadFields::firstItems($value);
    }
}
