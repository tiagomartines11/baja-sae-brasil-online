<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * How one source is doing, for the statistics block of the review screen.
 *
 * A pass that two sources recorded and a third did not is not a flag: it is
 * simply counted here as a miss ("falta") of the third.
 */
final class SourceStats
{
    public int $fonteId;

    /** Entries in use after decisions and automatic voids. */
    public int $entradas = 0;

    /** Counted passes this source was expected to record and did not. */
    public int $faltas = 0;

    /** Entries of this source that a reviewer reassigned to another car. */
    public int $trocas = 0;

    /** Double taps and handover duplicates the engine voided on its own. */
    public int $repetidasAnuladas = 0;

    /** Median of (this source's time - group time); positive means it types late. */
    public int $desvioMedianoMs = 0;

    /** True when no device of the source has been in contact for fonte_offline_s. */
    public bool $offline = false;

    public ?int $sincronizadoAteMs = null;
    public ?int $vistoEmMs = null;

    /** For an observer source only: how it compares with the official count. */
    public ?ObserverStats $observacao = null;

    /** Unread passes ("??") of this source from the start on, however they were settled. */
    public int $ilegiveis = 0;

    /** Of those, how many the engine gave a car by itself (see UnreadAssigner). */
    public int $ilegiveisAtribuidas = 0;

    /**
     * For a timing sensor only (taking part, not observing): 'marcas' (its
     * marks from the start on), 'atribuidas' (matched to a pass, which they
     * time), 'ambiguas' (near passes, but not safely paired with one),
     * 'sem_passagem' (no pass the counters recorded anywhere near: noise,
     * or a crossing everybody missed), 'passagens_sem_marca' (official
     * passes while it was taking part and synced, with no mark: its
     * misses), and 'desvio_mediano_ms' / 'desvio_p90_ms' (its mark minus
     * the counters' time of the pass, over the matched ones; null without).
     *
     * @var array{marcas: int, atribuidas: int, ambiguas: int, sem_passagem: int, passagens_sem_marca: int, desvio_mediano_ms: ?int, desvio_p90_ms: ?int}|null
     */
    public ?array $sensor = null;

    public function __construct(int $fonteId)
    {
        $this->fonteId = $fonteId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = [
            'fonte_id' => $this->fonteId,
            'entradas' => $this->entradas,
            'faltas' => $this->faltas,
            'trocas' => $this->trocas,
            'repetidas_anuladas' => $this->repetidasAnuladas,
            'desvio_mediano_ms' => $this->desvioMedianoMs,
            'offline' => $this->offline,
            'sincronizado_ate_ms' => $this->sincronizadoAteMs,
            'visto_em_ms' => $this->vistoEmMs,
        ];
        // Only an observer has the key at all: a race without one sends
        // exactly what it always did.
        if ($this->observacao !== null) {
            $array['observacao'] = $this->observacao->toArray();
        }
        // Likewise for unread passes and timing sensors.
        if ($this->ilegiveis > 0) {
            $array['ilegiveis'] = $this->ilegiveis;
            $array['ilegiveis_atribuidas'] = $this->ilegiveisAtribuidas;
        }
        if ($this->sensor !== null) {
            $array['sensor'] = $this->sensor;
        }
        return $array;
    }
}
