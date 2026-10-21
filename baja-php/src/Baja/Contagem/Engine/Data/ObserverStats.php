<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * How an observer source (a machine-vision system on trial) compares with
 * the official count. Worked out by ObserverComparator; shown beside the
 * source's statistics, and only for an observer.
 *
 * Every number is measured against the OFFICIAL passes, which the observer
 * took no part in: that is what makes it a fair test.
 */
final class ObserverStats
{
    public int $fonteId;

    /** Entries of the observer from the start on, with a usable time. */
    public int $entradas = 0;

    /** Entries matched to an official pass of the same car. */
    public int $acertos = 0;

    /** Entries with no official pass of that car (nor a misread) nearby: it saw a car that did not pass. */
    public int $falsosPositivos = 0;

    /**
     * Entries with no pass of their car, but an official pass of ANOTHER car
     * within MISREAD_WINDOW_MS that the observer did not record: it read
     * that car's number wrong.
     */
    public int $leiturasTrocadas = 0;

    /** Official passes, at moments the observer was observing and had synced, that it did not record at all. */
    public int $faltas = 0;

    /** Unread entries ("??"): it saw a car and could not read the number. Also counted in `entradas`. */
    public int $ilegiveis = 0;

    /** Of those, how many came within MISREAD_WINDOW_MS of an official pass it recorded nothing else for. */
    public int $ilegiveisComPassagem = 0;

    /** Median of (observer's time - pass time) over its hits; positive means it is late. Null without hits. */
    public ?int $desvioMedianoMs = null;

    /** 90th percentile of |observer's time - pass time| over its hits. Null without hits. */
    public ?int $desvioP90Ms = null;

    /**
     * The commonest misreads, most frequent first (at most TOP_MISREADS).
     *
     * @var array<int, array{carro_lido: int, carro_real: int, vezes: int}>
     */
    public array $trocasFrequentes = [];

    public const TOP_MISREADS = 5;

    public function __construct(int $fonteId)
    {
        $this->fonteId = $fonteId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entradas' => $this->entradas,
            'acertos' => $this->acertos,
            'falsos_positivos' => $this->falsosPositivos,
            'leituras_trocadas' => $this->leiturasTrocadas,
            'faltas' => $this->faltas,
            'ilegiveis' => $this->ilegiveis,
            'ilegiveis_com_passagem' => $this->ilegiveisComPassagem,
            'desvio_mediano_ms' => $this->desvioMedianoMs,
            'desvio_p90_ms' => $this->desvioP90Ms,
            'trocas_frequentes' => $this->trocasFrequentes,
        ];
    }
}
