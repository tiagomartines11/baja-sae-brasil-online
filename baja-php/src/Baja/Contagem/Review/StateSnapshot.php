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

        return [
            'fontes' => SourceOverview::describeSources($input, $result),
            'dispositivos' => SourceOverview::describeDevices($prova, $input->settings, $input->nowMs),
            'alertas_abertos' => $openFlags,
            'alertas_resolvidos' => $resolvedFlags,
            'carros' => self::describeCars($input, $result, $teams),
            'carros_validos' => self::describeValidCars($input, $teams),
            'configuracoes' => $input->settings->toArray(),
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
        foreach ($state['fontes'] ?? [] as $index => $source) {
            $contact = $live['contato_das_fontes'][(int) $source['id']] ?? null;
            if ($contact !== null && is_array($source['stats'] ?? null)) {
                $state['fontes'][$index]['stats'] = array_merge($source['stats'], $contact);
            }
        }
        $state['dispositivos'] = $live['dispositivos'];

        foreach (['versao', 'calculado_em_ms', 'servidor_agora_ms', 'corrida', 'revisores', 'publicado_em_ms', 'publicacao_desligada'] as $key) {
            $state[$key] = $live[$key];
        }
        return $state;
    }

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
        ];
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
     * The car table, without each car's status: "atrasado" is a statement
     * about this very second and is added by live().
     *
     * @param array<int, array{numero: int, nome: string, escola: string}> $teams
     * @return array<int, array<string, mixed>> in standings order
     */
    private static function describeCars(EngineInput $input, EngineResult $result, array $teams): array
    {
        $officialPassesByCar = [];
        foreach ($result->laps as $lap) {
            if ($lap->isOfficialPass()) {
                $officialPassesByCar[$lap->carro] = ($officialPassesByCar[$lap->carro] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($result->cars as $state) {
            $row = $state->toArray();
            unset($row['status'], $row['status_desde_ms']);
            // Official passes are laps plus the car's pass zero. A counter
            // types passes, so this is what each counter's own number is
            // compared with.
            $row['passagens'] = $officialPassesByCar[$state->carro] ?? 0;
            $row['equipe'] = $teams[$state->carro]['nome'] ?? null;
            $row['escola'] = $teams[$state->carro]['escola'] ?? null;

            $imported = [];
            foreach ($input->totals as $fonteId => $lapsByCar) {
                if (isset($lapsByCar[$state->carro])) {
                    $imported[$fonteId] = (int) $lapsByCar[$state->carro];
                }
            }
            $row['totais_importados'] = $imported;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * For the "reatribuir para…" and "adicionar passagem" inputs.
     *
     * @param array<int, array{numero: int, nome: string, escola: string}> $teams
     * @return array<int, array{numero: int, equipe: ?string}>
     */
    private static function describeValidCars(EngineInput $input, array $teams): array
    {
        $cars = $input->validCars;
        sort($cars);
        $rows = [];
        foreach ($cars as $car) {
            $rows[] = ['numero' => $car, 'equipe' => $teams[$car]['nome'] ?? null];
        }
        return $rows;
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
