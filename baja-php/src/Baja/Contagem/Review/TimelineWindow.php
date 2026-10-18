<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ChangeCounter;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\RedisClient;
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
            $service,
            $recount,
            fn(Entry $raw, ?int $effectiveCar): bool => $inWindow($raw->tsMs),
            fn(Ocorrencia $ocorrencia): bool => $inWindow($ocorrencia->tsMs),
            $fromMs,
            $untilMs,
            null
        );
    }

    /**
     * How long one answer of forWindowShared() is handed to other review
     * pages. Matches the review page's ATUALIZAR_NO_MAXIMO_A_CADA_MS: what
     * moves with the clock alone (a lone entry still waiting) is at most
     * one refresh behind.
     */
    public const SHARED_SECONDS = 5;

    /**
     * forWindow(), shared between review pages. The pieces sit on a fixed
     * grid, so every page following the race asks for the same ones at the
     * same moment, and each costs a recount of nearly the whole field. The
     * answer is kept under the prova's change count, which every upload,
     * decision, setting and race-control action raises: a change is never
     * hidden, only the same question asked again within a few seconds is
     * answered from memory. Without Redis every request computes its own.
     *
     * @return array<string, mixed>
     */
    public static function forWindowShared(ContagemService $service, int $fromMs, int $untilMs): array
    {
        $prova = $service->getProva();
        $changes = (new ChangeCounter((string) $prova->getEventoId(), (string) $prova->getProvaId()))->current();
        $redis = $changes === null ? null : RedisClient::get();
        if ($redis === null) {
            return self::forWindow($service, $fromMs, $untilMs);
        }
        $key = 'contagem:' . $prova->getEventoId() . ':' . $prova->getProvaId() . ':janela:' . $fromMs . ':' . $untilMs . ':' . $changes;
        try {
            $cached = $redis->get($key);
            $decoded = is_string($cached) ? json_decode($cached, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $error) {
            error_log('Contagem\TimelineWindow: cache read failed: ' . $error->getMessage());
        }
        $window = self::forWindow($service, $fromMs, $untilMs);
        try {
            $redis->setex($key, self::SHARED_SECONDS, (string) json_encode($window, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Throwable $error) {
            error_log('Contagem\TimelineWindow: cache write failed: ' . $error->getMessage());
        }
        return $window;
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
            $service,
            $recount,
            fn(Entry $raw, ?int $effectiveCar): bool => $raw->carroDigitado === $carro || $effectiveCar === $carro,
            fn(Ocorrencia $ocorrencia): bool => $ocorrencia->carro === null || $ocorrencia->carro === $carro,
            null,
            null,
            $carro
        );
    }

    /**
     * @param callable(Entry, ?int): bool $wantsEntry given the raw entry and the car it counts for now (null: an unread pass with no car)
     * @param callable(Ocorrencia): bool $wantsOcorrencia
     * @return array<string, mixed>
     */
    private static function describe(
        ContagemService $service,
        CarsRecount $recount,
        callable $wantsEntry,
        callable $wantsOcorrencia,
        ?int $fromMs,
        ?int $untilMs,
        ?int $carro
    ): array {
        $wanted = [];
        $decisionIds = [];
        foreach (array_merge($recount->input->entries, $recount->addedPasses()) as $raw) {
            $surviving = $recount->survivingEntry($raw->id);
            $effectiveCar = $surviving !== null ? $surviving->carro : $raw->carro;
            // A timing sensor's mark is shown with the car whose pass it times.
            $timedGroup = $recount->groupTimedBy($raw->id);
            if ($timedGroup !== null) {
                $effectiveCar = $timedGroup->carro;
            }
            if (!$wantsEntry($raw, $effectiveCar)) {
                continue;
            }
            $wanted[] = [$raw, $effectiveCar];
            foreach ($recount->signatures->decisionIdsOf($raw->id) as $decisaoId) {
                $decisionIds[$decisaoId] = $decisaoId;
            }
        }
        // Who decided, when and why: what a chip's tooltip says about a
        // void, and what "Restaurar" asks about a counter's own one.
        $people = DecisionHistory::peopleOf($service->getProva(), array_values($decisionIds));
        $shortConfirmed = self::shortConfirmedLaps($service);

        $entriesBySource = [];
        foreach ($wanted as [$raw, $effectiveCar]) {
            $entriesBySource[$raw->fonteId][] = self::describeEntry($recount, $raw, $effectiveCar, $people, $shortConfirmed);
        }
        $entriesBySource = self::withImages($service->getProva(), $entriesBySource);

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
     * The laps the last whole-race run marked as too short to be real and
     * counted on a reviewer's word (CarTable's `voltas_curtas_confirmadas`),
     * by the anchor entry of the pass that ends them. They need the pace of
     * the whole field, which a timeline does not compute: they are read
     * from the stored snapshot, as old as the flags on screen.
     *
     * @return array<int, true>
     */
    private static function shortConfirmedLaps(ContagemService $service): array
    {
        $anchors = [];
        $snapshot = $service->storedSnapshot();
        foreach ($snapshot === null ? [] : ($snapshot->dados['estado']['carros'] ?? []) as $row) {
            foreach ($row['voltas_curtas_confirmadas'] ?? [] as $lap) {
                $anchors[(int) $lap['ancora']] = true;
            }
        }
        return $anchors;
    }

    /**
     * @param array<int, array{usuario: ?string, criado_em_ms: int, motivo: ?string}> $people
     * @param array<int, true> $shortConfirmed anchors of the passes that end a short confirmed lap
     * @return array<string, mixed>
     */
    private static function describeEntry(CarsRecount $recount, Entry $raw, ?int $effectiveCar, array $people, array $shortConfirmed): array
    {
        $decisions = [];
        foreach ($recount->signatures->decisionIdsOf($raw->id) as $decisaoId) {
            $decision = $recount->activeDecisions[$decisaoId];
            $decisions[] = [
                'id' => $decisaoId,
                'tipo' => $decision->tipo,
                'origem' => $decision->origem,
                'usuario' => $people[$decisaoId]['usuario'] ?? null,
                'criado_em_ms' => $people[$decisaoId]['criado_em_ms'] ?? null,
                'motivo' => $people[$decisaoId]['motivo'] ?? null,
            ];
        }

        $group = $recount->groupOf($raw->id);
        // A timing sensor's mark is no entry of the pass it times, but it is
        // shown with it: its car, and its pass group.
        $timedGroup = $group === null ? $recount->groupTimedBy($raw->id) : null;
        if ($timedGroup !== null) {
            $group = $timedGroup;
        }
        $reasonNotInUse = $group === null ? $recount->reasonNotInUse($raw) : null;
        $autoVoided = $reasonNotInUse === CarsRecount::REPETIDA;
        $surviving = $recount->survivingEntry($raw->id);
        $assignedByTheEngine = ($surviving !== null && $surviving->atribuidoAutomaticamente) || $timedGroup !== null;

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
            'anulada_auto' => $autoVoided,
            // Only for an entry voided as a repeat: confirming brings it back
            // as a pass of its own, and this is the shortest lap it would make.
            'volta_resultante_ms' => $autoVoided && $effectiveCar !== null && $raw->qualidadeTempo !== Entry::TEMPO_DESCONHECIDO
                ? $recount->effect->shortestLapIfPassAt($effectiveCar, $raw->tsMs, null, true)
                : null,
            'reatribuida' => $effectiveCar !== $raw->carroDigitado && !$assignedByTheEngine,
            // A "??" (or a timing sensor's mark): stored with no car. With
            // `atribuida_automaticamente` the engine worked out its car
            // (`carro`) by itself; otherwise `carro` is null until somebody
            // says which car it was.
            'ilegivel' => $raw->carroDigitado === null,
            'atribuida_automaticamente' => $assignedByTheEngine,
            // A timing sensor's mark that times the pass in `grupo` (its
            // car is that pass's). A mark that times no pass is out of use
            // instead (`motivo_fora_de_uso` 'marca_do_sensor').
            'marca_do_sensor' => $timedGroup !== null,
            // In no group and nobody voided it: before the start, or its
            // source was off or excluded at that moment.
            'fora_de_uso' => in_array($reasonNotInUse, [
                CarsRecount::ANTES_DA_LARGADA, CarsRecount::FONTE_INATIVA, CarsRecount::FONTE_EXCLUIDA,
                CarsRecount::FONTE_OBSERVADORA, CarsRecount::TEMPO_DESCONHECIDO,
                CarsRecount::ILEGIVEL, CarsRecount::MARCA_DO_SENSOR,
            ], true),
            'motivo_fora_de_uso' => $reasonNotInUse,
            'adicionada_por_revisor' => $raw->fonteId === Source::REVISOR_ID,
            'decisoes' => $decisions,
            'grupo' => $group === null ? null : self::describeGroup($recount, $group, $shortConfirmed),
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
     * With it, `volta_resultante_ms` is the shortest lap that pass would
     * make (as on a flag), so the chip asks the same question K does.
     *
     * `volta_ms`: on a lap ('volta'), how long that lap took, as the car
     * table times it (habit-corrected), so a chip can say "volta 22 · 1:15".
     *
     * `volta_curta_confirmada`: the pass ends a lap too short to be real
     * that counts on a reviewer's confirmation. `tempo_escolhido_id`: the
     * entry a reviewer chose to time the pass ("usar_tempo"), or null.
     * `marca_sensor_id`: the timing sensor's mark matched to the pass, or
     * null; `tempo_do_sensor`: that mark is the pass time (`ts_ms`). Not
     * when a precise source in the group, or a reviewer's "usar_tempo",
     * times the pass: the mark is then only shown with it.
     *
     * @param array<int, true> $shortConfirmed
     * @return array<string, mixed>
     */
    private static function describeGroup(CarsRecount $recount, PassGroup $group, array $shortConfirmed): array
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

        $addsAPass = !$group->confirmadoPorRevisor && in_array($role, ['pendente', 'retida', 'antes_da_largada'], true)
            && !$group->contado;
        return [
            'ancora' => $group->anchorEntryId(),
            'passagem_ids' => $group->entryIds(),
            'ts_ms' => $group->tsMs,
            'papel' => $role,
            // "contado" keeps its old meaning for the screen: an official pass.
            'contado' => $role === 'volta' || $role === 'inicial' || ($role === 'antes_da_largada' && $group->contado),
            'numero_volta' => $group->numeroVolta,
            // The lap this pass ends, timed as in the car table; null unless it counts as a lap.
            'volta_ms' => $role === 'volta' ? $recount->lapTimeOf($group) : null,
            'confirmado_por_revisor' => $group->confirmadoPorRevisor,
            // 'proxima_demais' | 'dispersao'; null when the pass is not held.
            'motivo_retencao' => $held ? ($group->motivoRetencao ?? null) : null,
            'soma_ao_confirmar' => $addsAPass,
            'volta_resultante_ms' => $addsAPass ? $recount->effect->shortestLapIfPassAt($group->carro, $group->tsMs, $group, true) : null,
            'tempo_escolhido_id' => $group->tempoEscolhidoId,
            'marca_sensor_id' => $group->marcaSensor === null ? null : $group->marcaSensor->id,
            'tempo_do_sensor' => $group->tempoDoSensor,
            'volta_curta_confirmada' => isset($shortConfirmed[$group->anchorEntryId()]) && $group->contado,
        ];
    }

    /**
     * Adds `imagem_url` (and `confianca`, when the system sent it) to the
     * entries of the automatic sources that have an image base URL, where
     * the entry carries an image reference. Nothing is added, and no
     * question is asked of the entries, while no source has a base URL.
     *
     * The link is the source's base URL followed by the reference, as is:
     * the picture is served by the system itself (usually a box beside the
     * track), never by this server.
     *
     * @param array<int, array<int, array<string, mixed>>> $entriesBySource
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function withImages(Prova $prova, array $entriesBySource): array
    {
        $baseUrls = SourceAdmin::imageBaseUrls($prova);
        $entryIds = [];
        foreach ($baseUrls as $fonteId => $baseUrl) {
            foreach ($entriesBySource[$fonteId] ?? [] as $entry) {
                $entryIds[] = (int) $entry['id'];
            }
        }
        if ($entryIds === []) {
            return $entriesBySource;
        }

        $images = [];
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $statement = Propel::getReadConnection('resultados')->prepare(
                'SELECT passagem_id, imagem_ref, confianca FROM contagem_passagem
                  WHERE imagem_ref IS NOT NULL AND passagem_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $images[(int) $row['passagem_id']] = $row;
            }
        }
        foreach ($baseUrls as $fonteId => $baseUrl) {
            // An address saved before a path was required ("http://box"):
            // the id must not run into the host name.
            if (preg_match('#^https?://[^/]+$#i', $baseUrl) === 1) {
                $baseUrl .= '/';
            }
            foreach ($entriesBySource[$fonteId] ?? [] as $index => $entry) {
                $image = $images[(int) $entry['id']] ?? null;
                if ($image === null) {
                    continue;
                }
                $entriesBySource[$fonteId][$index]['imagem_url'] = $baseUrl . rawurlencode((string) $image['imagem_ref']);
                $entriesBySource[$fonteId][$index]['confianca'] = $image['confianca'] === null ? null : (float) $image['confianca'];
            }
        }
        return $entriesBySource;
    }

    /**
     * The numbers typed between two moments. This is the one question a
     * window has to ask the whole race, so it is asked in SQL.
     *
     * @return int[]
     */
    private static function carsTypedBetween(Prova $prova, int $fromMs, int $untilMs): array
    {
        // The index is named: right after a race is reset, the table's
        // statistics are stale and MySQL picks the uuid index instead,
        // reading every entry of each source (10 ms against 1 ms), and this
        // runs for every piece of the timeline.
        $sql = 'SELECT DISTINCT p.equipe_numero
                  FROM contagem_passagem p FORCE INDEX (contagem_passagem_fonte_ts_idx)
                  JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                 WHERE f.evento_id = :evento AND f.prova_id = :prova
                   AND p.ts_ms BETWEEN :de AND :ate AND p.equipe_numero IS NOT NULL';
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
            $lanes[] = ['fonte_id' => $source->id, 'nome' => $source->nome, 'tipo' => $source->tipo, 'ativa' => $source->ativa, 'papel' => $source->papel];
        }
        if ($withReviewerLane) {
            $lanes[] = ['fonte_id' => Source::REVISOR_ID, 'nome' => 'Revisor', 'tipo' => 'revisor', 'ativa' => true, 'papel' => Source::PAPEL_VOTANTE];
        }
        return $lanes;
    }
}
