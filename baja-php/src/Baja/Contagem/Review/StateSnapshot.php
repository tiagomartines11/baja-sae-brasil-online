<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LiveStatus;
use Baja\Contagem\Service\RaceSnapshot;

/**
 * Everything the review screen draws, as one array ready for json_encode.
 *
 * It is made in two steps, because the two halves have different lifetimes:
 *
 * fromResult()  what a full engine run means for the screen: flags, the car
 *               table, source statistics, the decision history. It is stored
 *               inside the race snapshot and served to every reviewer for a
 *               few seconds, so NOTHING in it may depend on the clock or on
 *               who is looking.
 *
 * live()        the stored half plus what changes on its own: the race
 *               clock, which car is late, which phone went quiet, who else
 *               is reviewing. Worked out at the moment of each answer with a
 *               little arithmetic and one small query; the engine never runs
 *               for a poll.
 *
 * The screen always receives the whole picture, never a difference: a
 * browser that missed a few polls is correct again on the next one.
 */
final class StateSnapshot
{
    /**
     * Top-level keys of the stored half that change with every heartbeat of
     * a phone although nothing about the race changed: when each device was
     * last heard from and how far each has synced.
     *
     * The snapshot's token (Service\SnapshotService) is a digest of the
     * stored half WITHOUT these keys. With them in, the token changed every
     * few seconds and every review screen downloaded the whole state again
     * for nothing. Whatever is under these keys is also sent with the small
     * "inalterado" answer, worked out afresh (see liveParts()), so a screen
     * that keeps its state still sees them move.
     *
     * A number that moves with the heartbeats must live under one of these
     * keys and nowhere else in the stored half.
     */
    public const LIVE_KEYS = ['dispositivos', 'contato_das_fontes'];

    /** The parts of a source's statistics that are contact, not count: kept under 'contato_das_fontes'. */
    private const CONTACT_FIELDS = ['visto_em_ms', 'offline', 'sincronizado_ate_ms', 'nada_a_esperar'];

    /**
     * The stored half. Called by Service\SnapshotBuilder after every full run.
     *
     * @return array<string, mixed>
     */
    public static function fromResult(ContagemService $service, EngineInput $input, EngineResult $result): array
    {
        $prova = $service->getProva();
        $signatures = SignatureCalculator::forResult($result, $input->decisions);
        $teams = $service->loadTeams(false);
        $activeDecisionIds = array_fill_keys(array_keys(ActiveDecisions::filter($input->decisions)), true);

        $openFlags = [];
        $resolvedFlags = [];
        foreach ($result->flags as $flag) {
            $row = self::describeFlag($flag, $signatures);
            if ($flag->autoResolvido) {
                $resolvedFlags[] = $row;
            } else {
                $openFlags[] = $row;
            }
        }

        $history = DecisionHistory::page($prova, $activeDecisionIds, null);
        [$sources, $contactBySource] = self::withoutContact(SourceOverview::describeSources($input, $result));

        return [
            'fontes' => $sources,
            'contato_das_fontes' => $contactBySource,
            'dispositivos' => SourceOverview::describeDevices($prova, $input->settings, $input->nowMs),
            'alertas_abertos' => $openFlags,
            'alertas_resolvidos' => $resolvedFlags,
            'carros' => CarTable::describe($input, $result, $teams),
            'carros_validos' => CarTable::describeValidCars($input, $teams),
            'sem_equipes_presentes' => CarTable::nobodyIsPresent($input),
            'configuracoes' => $input->settings->toArray(),
            'paradas_sem_par' => TimelineStops::describeUnpaired($result->timeline),
            'paradas_sem_horario' => TimelineStops::describeUnknownTime($result->timeline),
            // Exits the engine assumed (the log had the car inside while it
            // was plainly lapping). Nothing is stored for them; the screen
            // offers "Confirmar saída", which stores one.
            'saidas_presumidas' => TimelineStops::describeAssumedExits($result->timeline),
            'historico' => $history['linhas'],
            'historico_ha_mais' => $history['ha_mais'],
            'historico_em_vigor' => DecisionHistory::inForce($prova, $activeDecisionIds),
            'ultima_decisao_id' => self::highestDecisionId($input),
        ];
    }

    /**
     * The full state for one reviewer, right now.
     *
     * @param string|null $viewerUsername left out of the "who is looking" lists (you know where you are)
     * @return array<string, mixed>
     */
    public static function live(ContagemService $service, RaceSnapshot $snapshot, ?string $viewerUsername): array
    {
        $state = is_array($snapshot->dados['estado'] ?? null) ? $snapshot->dados['estado'] : [];
        $live = self::liveParts($service, $snapshot, $viewerUsername);

        foreach (['alertas_abertos', 'alertas_resolvidos'] as $list) {
            foreach ($state[$list] ?? [] as $index => $flag) {
                $state[$list][$index]['olhando'] = $live['olhando'][$flag['id']] ?? [];
            }
        }
        foreach ($state['carros'] ?? [] as $index => $car) {
            $status = $live['status_dos_carros'][(int) $car['carro']] ?? null;
            $state['carros'][$index]['status'] = $status['status'] ?? null;
            $state['carros'][$index]['status_desde_ms'] = $status['status_desde_ms'] ?? null;
        }
        // The screen reads a source's contact from its statistics. It is
        // stored apart (see LIVE_KEYS) and put back here: first as it was
        // when the snapshot was made, then as it is right now.
        $storedContact = is_array($state['contato_das_fontes'] ?? null) ? $state['contato_das_fontes'] : [];
        foreach ($state['fontes'] ?? [] as $index => $source) {
            if (!is_array($source['stats'] ?? null)) {
                continue;
            }
            $fonteId = (int) $source['id'];
            $state['fontes'][$index]['stats'] = array_merge(
                $source['stats'],
                $storedContact[$fonteId] ?? [],
                $live['contato_das_fontes'][$fonteId] ?? []
            );
        }
        unset($state['contato_das_fontes']);
        $state['dispositivos'] = $live['dispositivos'];

        foreach (self::LIVE_STATE_KEYS as $key) {
            $state[$key] = $live[$key];
        }
        return $state;
    }

    /** Keys of liveParts() that the full state carries under the same name. */
    private const LIVE_STATE_KEYS = [
        'versao', 'calculado_em_ms', 'servidor_agora_ms', 'corrida', 'revisores', 'publicado_em_ms',
        'publicacao_desligada', 'simulacao', 'predefinicao_de_teste', 'correcoes_da_reconciliacao',
    ];

    /**
     * Only what changes with time or with the viewer. This is the whole
     * answer when the browser already has the current snapshot
     * ("inalterado"): the clock keeps running and a car still becomes late
     * while nothing new is typed.
     *
     * @return array<string, mixed>
     */
    public static function liveParts(ContagemService $service, RaceSnapshot $snapshot, ?string $viewerUsername): array
    {
        $prova = $service->getProva();
        $settings = $service->getSettings();
        $nowMs = Clock::nowMs();
        $liveNumbers = is_array($snapshot->dados['tempo_real'] ?? null) ? $snapshot->dados['tempo_real'] : [];

        // Phones call the server every few seconds whether or not anybody
        // typed, and a silent phone changes nothing in the database. So the
        // stored half cannot know who is in contact NOW; this one small
        // query can.
        $devices = SourceOverview::describeDevices($prova, $settings, $nowMs);

        return [
            'versao' => $snapshot->token,
            'calculado_em_ms' => $snapshot->geradoEmMs,
            'servidor_agora_ms' => $nowMs,
            'corrida' => LiveStatus::ofRace($liveNumbers, $nowMs),
            'status_dos_carros' => LiveStatus::ofCars($liveNumbers, $settings, $nowMs),
            'dispositivos' => $devices,
            'contato_das_fontes' => SourceOverview::contactBySource(
                $snapshot->dados['estado']['fontes'] ?? [],
                $devices,
                $settings,
                $liveNumbers,
                $nowMs
            ),
            'olhando' => self::watchingWithoutViewer(ReviewerPresence::current($prova), $viewerUsername),
            'revisores' => ReviewerPresence::online($prova),
            'publicado_em_ms' => $service->lastPublishedAtMs(),
            'publicacao_desligada' => !$settings->publishesToScoring(),
            // Drives the permanent "SIMULAÇÃO" banner. Sent with every
            // answer, so the banner follows the setting within one poll.
            'simulacao' => $settings->permitirSimulacao,
            // The test preset is applied: the time settings in force are
            // NOT the real race's. Shown as a banner for as long as it lasts.
            'predefinicao_de_teste' => $service->hasSavedSettings(),
            // What the slow path had to put right in the published counts
            // during the last day. It should never happen; when it does the
            // reviewers are the ones who must know, not only the error log.
            'correcoes_da_reconciliacao' => $service->lastReconcileCorrections(),
        ];
    }

    /**
     * Takes the contact fields out of each source's statistics.
     *
     * @param array<int, array<string, mixed>> $sourceRows as made by SourceOverview::describeSources()
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} the rows without them, and fonteId => those fields
     */
    private static function withoutContact(array $sourceRows): array
    {
        $contactBySource = [];
        foreach ($sourceRows as $index => $row) {
            if (!is_array($row['stats'] ?? null)) {
                continue;
            }
            foreach (self::CONTACT_FIELDS as $field) {
                if (array_key_exists($field, $row['stats'])) {
                    $contactBySource[(int) $row['id']][$field] = $row['stats'][$field];
                    unset($sourceRows[$index]['stats'][$field]);
                }
            }
        }
        return [$sourceRows, $contactBySource];
    }

    /**
     * The answer to a poll whose `versao` is the current token.
     *
     * @return array<string, mixed>
     */
    public static function unchanged(ContagemService $service, RaceSnapshot $snapshot, ?string $viewerUsername): array
    {
        $live = self::liveParts($service, $snapshot, $viewerUsername);
        $live['inalterado'] = true;
        // Cast so that empty lists are {} in JSON like filled ones, not [].
        $live['olhando'] = (object) $live['olhando'];
        $live['status_dos_carros'] = (object) $live['status_dos_carros'];
        $live['contato_das_fontes'] = (object) $live['contato_das_fontes'];
        return $live;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeFlag(Flag $flag, SignatureCalculator $signatures): array
    {
        $row = $flag->toArray();
        $row['assinatura'] = $signatures->forEntries($flag->passagemIds);
        return $row;
    }

    /**
     * @param array<string, string[]> $watching flagId => usernames
     * @return array<string, string[]>
     */
    private static function watchingWithoutViewer(array $watching, ?string $viewerUsername): array
    {
        if ($viewerUsername === null) {
            return $watching;
        }
        $others = [];
        foreach ($watching as $flagId => $usernames) {
            $rest = array_values(array_filter($usernames, fn($name) => $name !== $viewerUsername));
            if ($rest !== []) {
                $others[$flagId] = $rest;
            }
        }
        return $others;
    }

    /**
     * Sent back by the browser with every decision; lets a refusal tell
     * "somebody decided after your screen was drawn" from "the data changed".
     */
    private static function highestDecisionId(EngineInput $input): int
    {
        $highest = 0;
        foreach ($input->decisions as $decision) {
            $highest = max($highest, $decision->id);
        }
        return $highest;
    }
}
