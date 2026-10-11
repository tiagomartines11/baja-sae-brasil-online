<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Service\ContagemService;

/**
 * The lane view of the review screen: one lane per source with what that
 * source typed, plus the pit lane.
 *
 * Unlike the engine's result, this shows the raw entries too, including the
 * ones that were voided, because a reviewer deciding about a flag needs to
 * see what each counter actually did, not only what survived.
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
        $input = $service->loadInput();
        $result = Engine::run($input);

        $inWindow = fn(int $tsMs) => $tsMs >= $fromMs && $tsMs <= $untilMs;

        return self::describe(
            $input,
            $result,
            fn(Entry $raw, ?Entry $inUse) => $inWindow($raw->tsMs),
            fn(Ocorrencia $ocorrencia) => $inWindow($ocorrencia->tsMs),
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
        $input = $service->loadInput();
        $result = Engine::run($input);

        return self::describe(
            $input,
            $result,
            fn(Entry $raw, ?Entry $inUse) => $raw->carroDigitado === $carro
                || $raw->carro === $carro
                || ($inUse !== null && $inUse->carro === $carro),
            fn(Ocorrencia $ocorrencia) => $ocorrencia->carro === null || $ocorrencia->carro === $carro,
            null,
            null,
            $carro
        );
    }

    /**
     * @param callable(Entry, ?Entry): bool $wantsEntry
     * @param callable(Ocorrencia): bool $wantsOcorrencia
     * @return array<string, mixed>
     */
    private static function describe(
        EngineInput $input,
        EngineResult $result,
        callable $wantsEntry,
        callable $wantsOcorrencia,
        ?int $fromMs,
        ?int $untilMs,
        ?int $carro
    ): array {
        $signatures = new SignatureCalculator($result, $input->decisions);
        $activeDecisions = ActiveDecisions::filter($input->decisions);
        $autoVoided = array_flip($result->autoVoidedEntryIds);

        $entriesBySource = [];
        foreach (self::rawAndAddedEntries($input, $result) as $raw) {
            $inUse = $signatures->entryInUse($raw->id);
            if (!$wantsEntry($raw, $inUse)) {
                continue;
            }
            $entriesBySource[$raw->fonteId][] = self::describeEntry($raw, $inUse, $signatures, $activeDecisions, $autoVoided);
        }

        $lanes = [];
        foreach (self::laneSources($input, isset($entriesBySource[Source::REVISOR_ID])) as $laneSource) {
            $entries = $entriesBySource[$laneSource['fonte_id']] ?? [];
            usort($entries, fn(array $a, array $b) => [$a['ts_ms'], $a['id']] <=> [$b['ts_ms'], $b['id']]);
            $laneSource['entradas'] = $entries;
            $lanes[] = $laneSource;
        }

        return [
            'de_ms' => $fromMs,
            'ate_ms' => $untilMs,
            'carro' => $carro,
            'largada_ms' => $result->timeline->largadaMs,
            'encerramento_ms' => $result->timeline->encerramentoMs,
            'raias' => $lanes,
            'ocorrencias' => self::describeOcorrencias($input->ocorrencias, $wantsOcorrencia),
        ];
    }

    /**
     * @param array<int, Decision> $activeDecisions
     * @param array<int, int> $autoVoided entry id => anything
     * @return array<string, mixed>
     */
    private static function describeEntry(
        Entry $raw,
        ?Entry $inUse,
        SignatureCalculator $signatures,
        array $activeDecisions,
        array $autoVoided
    ): array {
        $decisions = [];
        $voidedByPerson = false;
        foreach ($signatures->decisionIdsOf($raw->id) as $decisaoId) {
            $tipo = $activeDecisions[$decisaoId]->tipo;
            $decisions[] = ['id' => $decisaoId, 'tipo' => $tipo, 'origem' => $activeDecisions[$decisaoId]->origem];
            $voidedByPerson = $voidedByPerson || $tipo === Decision::ANULAR;
        }

        $group = $signatures->groupOf($raw->id);
        $effectiveCar = $inUse !== null ? $inUse->carro : $raw->carro;

        return [
            'id' => $raw->id,
            'fonte_id' => $raw->fonteId,
            'carro' => $effectiveCar,
            'carro_digitado' => $raw->carroDigitado,
            'ts_ms' => $raw->tsMs,
            'anulada' => $voidedByPerson,
            'anulada_auto' => isset($autoVoided[$raw->id]),
            'reatribuida' => $effectiveCar !== $raw->carroDigitado,
            // Not voided by anyone, yet not in use: its source was excluded
            // for that period, or it was typed before the start.
            'fora_de_uso' => $inUse === null && !$voidedByPerson && !isset($autoVoided[$raw->id]),
            'adicionada_por_revisor' => $raw->fonteId === Source::REVISOR_ID,
            'decisoes' => $decisions,
            'grupo' => $group === null ? null : [
                'ancora' => $group->anchorEntryId(),
                'passagem_ids' => $group->entryIds(),
                'ts_ms' => $group->tsMs,
                'contado' => $group->contado,
                'numero_volta' => $group->numeroVolta,
                'confirmado_por_revisor' => $group->confirmadoPorRevisor,
                'alertas' => $group->alertasAbertos,
            ],
            'assinatura' => $signatures->forEntries([$raw->id]),
        ];
    }

    /**
     * The raw entries plus the passes a reviewer added, which are not rows
     * of contagem_passagem and so only exist inside the engine's groups.
     *
     * @return Entry[]
     */
    private static function rawAndAddedEntries(EngineInput $input, EngineResult $result): array
    {
        $entries = $input->entries;
        foreach ($result->groups as $group) {
            foreach ($group->entries as $entry) {
                if ($entry->fonteId === Source::REVISOR_ID) {
                    $entries[] = $entry;
                }
            }
        }
        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>> the counting sources in display order, then the reviewer lane if used
     */
    private static function laneSources(EngineInput $input, bool $withReviewerLane): array
    {
        $sources = array_values(array_filter(
            $input->sources,
            fn(Source $source) => in_array($source->tipo, [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true)
        ));
        usort($sources, fn(Source $a, Source $b) => [$a->ordem, $a->id] <=> [$b->ordem, $b->id]);

        $lanes = [];
        foreach ($sources as $source) {
            $lanes[] = ['fonte_id' => $source->id, 'nome' => $source->nome, 'tipo' => $source->tipo, 'ativa' => $source->ativa];
        }
        if ($withReviewerLane) {
            $lanes[] = ['fonte_id' => Source::REVISOR_ID, 'nome' => 'Revisor', 'tipo' => 'revisor', 'ativa' => true];
        }
        return $lanes;
    }

    /**
     * @param Ocorrencia[] $ocorrencias
     * @param callable(Ocorrencia): bool $wantsOcorrencia
     * @return array<int, array<string, mixed>> pit and race-control events, in time order
     */
    private static function describeOcorrencias(array $ocorrencias, callable $wantsOcorrencia): array
    {
        // A row that another row replaces was a mistake; the engine ignores
        // it and so does the timeline.
        $replaced = [];
        foreach ($ocorrencias as $ocorrencia) {
            if ($ocorrencia->substituiId !== null) {
                $replaced[$ocorrencia->substituiId] = true;
            }
        }

        $rows = [];
        foreach ($ocorrencias as $ocorrencia) {
            if (isset($replaced[$ocorrencia->id]) || !$wantsOcorrencia($ocorrencia)) {
                continue;
            }
            $rows[] = [
                'id' => $ocorrencia->id,
                'tipo' => $ocorrencia->tipo,
                'carro' => $ocorrencia->carro,
                'ts_ms' => $ocorrencia->tsMs,
                'box' => in_array($ocorrencia->tipo, Ocorrencia::TIPOS_BOX, true),
            ];
        }
        usort($rows, fn(array $a, array $b) => [$a['ts_ms'], $a['id']] <=> [$b['ts_ms'], $b['id']]);
        return $rows;
    }
}
