<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * The lane view of the review screen: one lane per source with what that
 * source typed, plus the pit lane and the fueling station (TimelineStops).
 *
 * Unlike the engine's result, this shows the raw entries too, including the
 * ones that were voided, because a reviewer deciding about a flag needs to
 * see what each counter actually did, not only what survived.
 *
 * It never runs the whole engine. A timeline concerns a handful of cars, so
 * only those are recounted (see CarsRecount), which costs a few
 * milliseconds however long the race is.
 */
final class TimelineWindow
{
    /** A window is meant to be about a minute; refuse to dump the whole race by accident. */
    public const MAX_WINDOW_MS = 30 * 60 * 1000;

    /**
     * Every entry and event between two moments.
     *
     * @return array<string, mixed>
     */
    public static function forWindow(ContagemService $service, int $fromMs, int $untilMs): array
    {
        $untilMs = min($untilMs, $fromMs + self::MAX_WINDOW_MS);
        $inWindow = fn(int $tsMs): bool => $tsMs >= $fromMs && $tsMs <= $untilMs;

        $cars = self::carsTypedBetween($service->getProva(), $fromMs, $untilMs);
        $recount = CarsRecount::load($service, $cars, [$fromMs, $untilMs]);

        return self::describe(
            $recount,
            fn(Entry $raw, int $effectiveCar): bool => $inWindow($raw->tsMs),
            fn(Ocorrencia $ocorrencia): bool => $inWindow($ocorrencia->tsMs),
            $fromMs,
            $untilMs,
            null
        );
    }

    /**
     * One car's whole race: every entry typed as that car or reassigned to
     * it, and its pit stops.
     *
     * @return array<string, mixed>
     */
    public static function forCar(ContagemService $service, int $carro): array
    {
        $recount = CarsRecount::load($service, [$carro]);

        return self::describe(
            $recount,
            fn(Entry $raw, int $effectiveCar): bool => $raw->carroDigitado === $carro || $effectiveCar === $carro,
            fn(Ocorrencia $ocorrencia): bool => $ocorrencia->carro === null || $ocorrencia->carro === $carro,
            null,
            null,
            $carro
        );
    }

    /**
     * @param callable(Entry, int): bool $wantsEntry given the raw entry and the car it counts for now
     * @param callable(Ocorrencia): bool $wantsOcorrencia
     * @return array<string, mixed>
     */
    private static function describe(
        CarsRecount $recount,
        callable $wantsEntry,
        callable $wantsOcorrencia,
        ?int $fromMs,
        ?int $untilMs,
        ?int $carro
    ): array {
        $entriesBySource = [];
        foreach (array_merge($recount->input->entries, $recount->addedPasses()) as $raw) {
            $surviving = $recount->survivingEntry($raw->id);
            $effectiveCar = $surviving !== null ? $surviving->carro : $raw->carro;
            if (!$wantsEntry($raw, $effectiveCar)) {
                continue;
            }
            $entriesBySource[$raw->fonteId][] = self::describeEntry($recount, $raw, $effectiveCar);
        }

        $lanes = [];
        foreach (self::laneSources($recount->input->sources, isset($entriesBySource[Source::REVISOR_ID])) as $laneSource) {
            $entries = $entriesBySource[$laneSource['fonte_id']] ?? [];
            usort($entries, fn(array $a, array $b) => [$a['ts_ms'], $a['id']] <=> [$b['ts_ms'], $b['id']]);
            $laneSource['entradas'] = $entries;
            $lanes[] = $laneSource;
        }

        return [
            'de_ms' => $fromMs,
            'ate_ms' => $untilMs,
            'carro' => $carro,
            'largada_ms' => $recount->timeline->largadaMs,
            'encerramento_ms' => $recount->timeline->encerramentoMs,
            'raias' => $lanes,
            'ocorrencias' => TimelineStops::describeOcorrencias($recount->input->ocorrencias, $recount->timeline, $wantsOcorrencia),
            'estadias' => TimelineStops::describeStays($recount->timeline, $carro, $fromMs, $untilMs),
            // Stops whose time nobody knows have no place on the lanes; the
            // ones of the cars shown are listed under them.
            'paradas_sem_horario' => TimelineStops::describeUnknownTime($recount->timeline, $carro !== null ? [$carro] : $recount->cars),
            'abastecimento' => $recount->input->settings->abastecimento,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeEntry(CarsRecount $recount, Entry $raw, int $effectiveCar): array
    {
        $decisions = [];
        foreach ($recount->signatures->decisionIdsOf($raw->id) as $decisaoId) {
            $decision = $recount->activeDecisions[$decisaoId];
            $decisions[] = ['id' => $decisaoId, 'tipo' => $decision->tipo, 'origem' => $decision->origem];
        }

        $group = $recount->groupOf($raw->id);
        $reasonNotInUse = $group === null ? $recount->reasonNotInUse($raw) : null;

        return [
            'id' => $raw->id,
            'fonte_id' => $raw->fonteId,
            'carro' => $effectiveCar,
            'carro_digitado' => $raw->carroDigitado,
            'ts_ms' => $raw->tsMs,
            // 'exato', 'estimado' (stamped from an anchor of an earlier page
            // load) or 'desconhecido' (the phone's own clock: the entry is
            // in no group and its place on the timeline means nothing).
            'qualidade_tempo' => $raw->qualidadeTempo,
            'anulada' => $reasonNotInUse === CarsRecount::ANULADA,
            'anulada_auto' => $reasonNotInUse === CarsRecount::REPETIDA,
            'reatribuida' => $effectiveCar !== $raw->carroDigitado,
            // In no group and nobody voided it: before the start, or its
            // source was off or excluded at that moment.
            'fora_de_uso' => in_array($reasonNotInUse, [CarsRecount::ANTES_DA_LARGADA, CarsRecount::FONTE_INATIVA, CarsRecount::FONTE_EXCLUIDA, CarsRecount::TEMPO_DESCONHECIDO], true),
            'motivo_fora_de_uso' => $reasonNotInUse,
            'adicionada_por_revisor' => $raw->fonteId === Source::REVISOR_ID,
            'decisoes' => $decisions,
            'grupo' => $group === null ? null : self::describeGroup($group),
            'assinatura' => $recount->signatures->forEntries([$raw->id]),
        ];
    }

    /**
     * The pass group of an entry and the part it plays in the count:
     *
     *   papel 'volta'              an official pass that completes lap `numero_volta`
     *   papel 'inicial'            the car's pass zero: official, but not a lap
     *   papel 'janela_largada'     typed inside the start window; never a lap
     *   papel 'apos_encerramento'  a pass after the finish that the rules leave out
     *   papel 'pendente'           does not count yet (one counter only)
     *   papel 'antes_da_largada'   typed shortly before the start; counts
     *                              (as the car's pass zero) only once a
     *                              reviewer confirmed it
     *   papel 'retida'             HELD: two counters agree, but the pass is
     *                              too close to the car's previous one (or
     *                              the two entries are too far apart) to be
     *                              counted without a person's word
     *
     * `soma_ao_confirmar` says whether "confirmar" on an entry of the group
     * would give the car one more official pass. The screen writes
     * "+1 volta" on the button from it, and only from it: a pass the rules
     * of the race leave out (start window, after the finish) stays out
     * whoever confirms it, and a button that promised a lap there lied.
     *
     * @return array<string, mixed>
     */
    private static function describeGroup(PassGroup $group): array
    {
        // The start window is asked first: the engine numbers a car's first
        // crossing typed inside it as pass 0 too, and the reviewer should be
        // told the more specific thing.
        $held = ($group->retida ?? false) && !$group->contado;
        if ($group->antesDaLargada) {
            $role = 'antes_da_largada';
        } elseif ($held) {
            $role = 'retida';
        } elseif ($group->dentroDaJanelaDeLargada) {
            $role = 'janela_largada';
        } elseif ($group->numeroVolta === 0) {
            $role = 'inicial';
        } elseif ($group->ignoradoAposEncerramento) {
            $role = 'apos_encerramento';
        } elseif ($group->contado) {
            $role = 'volta';
        } else {
            $role = 'pendente';
        }

        return [
            'ancora' => $group->anchorEntryId(),
            'passagem_ids' => $group->entryIds(),
            'ts_ms' => $group->tsMs,
            'papel' => $role,
            // "contado" keeps its old meaning for the screen: an official pass.
            'contado' => $role === 'volta' || $role === 'inicial' || ($role === 'antes_da_largada' && $group->contado),
            'numero_volta' => $group->numeroVolta,
            'confirmado_por_revisor' => $group->confirmadoPorRevisor,
            // 'proxima_demais' | 'dispersao'; null when the pass is not held.
            'motivo_retencao' => $held ? ($group->motivoRetencao ?? null) : null,
            'soma_ao_confirmar' => !$group->confirmadoPorRevisor && in_array($role, ['pendente', 'retida', 'antes_da_largada'], true)
                && !$group->contado,
        ];
    }

    /**
     * The numbers typed between two moments. This is the one question a
     * window has to ask the whole race, so it is asked in SQL.
     *
     * @return int[]
     */
    private static function carsTypedBetween(Prova $prova, int $fromMs, int $untilMs): array
    {
        $sql = 'SELECT DISTINCT p.equipe_numero
                  FROM contagem_passagem p
                  JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                 WHERE f.evento_id = :evento AND f.prova_id = :prova
                   AND p.ts_ms BETWEEN :de AND :ate';
        $statement = Propel::getReadConnection('resultados')->prepare($sql);
        $statement->execute([
            ':evento' => $prova->getEventoId(),
            ':prova' => $prova->getProvaId(),
            ':de' => $fromMs,
            ':ate' => $untilMs,
        ]);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param Source[] $sources
     * @return array<int, array<string, mixed>> the counting sources in display order, then the reviewer lane if used
     */
    private static function laneSources(array $sources, bool $withReviewerLane): array
    {
        $counting = array_values(array_filter($sources, fn(Source $source) => $source->recordsPasses()));
        usort($counting, fn(Source $a, Source $b) => [$a->ordem, $a->id] <=> [$b->ordem, $b->id]);

        $lanes = [];
        foreach ($counting as $source) {
            $lanes[] = ['fonte_id' => $source->id, 'nome' => $source->nome, 'tipo' => $source->tipo, 'ativa' => $source->ativa];
        }
        if ($withReviewerLane) {
            $lanes[] = ['fonte_id' => Source::REVISOR_ID, 'nome' => 'Revisor', 'tipo' => 'revisor', 'ativa' => true];
        }
        return $lanes;
    }
}
