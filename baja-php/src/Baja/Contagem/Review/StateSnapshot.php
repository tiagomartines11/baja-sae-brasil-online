<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\EquipeQuery;
use Baja\Model\Prova;

/**
 * Everything the review screen draws, as one array ready for json_encode.
 *
 * The screen polls for this every two seconds and redraws from it, so it is
 * always the whole picture and never a difference: a browser that missed a
 * few polls is correct again on the next one.
 */
final class StateSnapshot
{
    /**
     * Loads the race, runs the engine and describes the result. No writes.
     *
     * @param string|null $viewerUsername left out of the "who is looking" lists (you know where you are)
     * @return array<string, mixed>
     */
    public static function build(ContagemService $service, ?string $viewerUsername = null): array
    {
        $input = $service->loadInput();
        return self::fromLoaded($service, $input, Engine::run($input), $viewerUsername);
    }

    /**
     * Same as build() for a caller that already ran the engine, so a refused
     * decision does not load forty thousand entries twice.
     *
     * @return array<string, mixed>
     */
    public static function fromLoaded(
        ContagemService $service,
        EngineInput $input,
        EngineResult $result,
        ?string $viewerUsername = null
    ): array {
        $prova = $service->getProva();
        $nowMs = Clock::nowMs();
        $settings = $service->getSettings();
        $signatures = new SignatureCalculator($result, $input->decisions);
        $watching = self::watchingWithoutViewer(ReviewerPresence::current($prova), $viewerUsername);
        $teams = self::teamsByNumber($prova);

        $openFlags = [];
        $resolvedFlags = [];
        foreach ($result->flags as $flag) {
            $row = self::describeFlag($flag, $signatures, $watching);
            if ($flag->autoResolvido) {
                $resolvedFlags[] = $row;
            } else {
                $openFlags[] = $row;
            }
        }

        return [
            'versao' => $service->currentVersion(),
            'publicado_em_ms' => $service->lastPublishedAtMs(),
            'servidor_agora_ms' => $nowMs,
            'corrida' => $result->timeline->toArray($nowMs),
            'fontes' => self::describeSources($input, $result, $settings->fontePrincipalId),
            'alertas_abertos' => $openFlags,
            'alertas_resolvidos' => $resolvedFlags,
            'carros' => self::describeCars($input, $result, $teams),
            'carros_validos' => self::describeValidCars($input, $teams),
            'configuracoes' => $settings->toArray(),
            'historico' => DecisionHistory::rows($prova, $input->decisions),
            'ultima_decisao_id' => self::highestDecisionId($input),
            'revisores' => ReviewerPresence::online($prova),
        ];
    }

    /**
     * @param array<string, string[]> $watching flagId => usernames
     * @return array<string, mixed>
     */
    private static function describeFlag(Flag $flag, SignatureCalculator $signatures, array $watching): array
    {
        $row = $flag->toArray();
        $row['assinatura'] = $signatures->forEntries($flag->passagemIds);
        $row['olhando'] = $watching[$flag->id] ?? [];
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>> counting and totals sources, in display order
     */
    private static function describeSources(EngineInput $input, EngineResult $result, ?int $primarySourceId): array
    {
        $sources = array_values(array_filter(
            $input->sources,
            // The pit log's internal source is plumbing, not something to review.
            fn(Source $source) => $source->tipo !== Source::TIPO_BOX
        ));
        usort($sources, fn(Source $a, Source $b) => [$a->ordem, $a->id] <=> [$b->ordem, $b->id]);

        $rows = [];
        foreach ($sources as $source) {
            $stats = $result->sourceStats[$source->id] ?? null;
            $rows[] = [
                'id' => $source->id,
                'nome' => $source->nome,
                'tipo' => $source->tipo,
                'qualidade_tempo' => $source->qualidadeTempo,
                'ativa' => $source->ativa,
                'principal' => $primarySourceId !== null && $primarySourceId === $source->id,
                'stats' => $stats !== null ? $stats->toArray() : null,
            ];
        }
        return $rows;
    }

    /**
     * @param array<int, array{equipe: string, escola: string}> $teams
     * @return array<int, array<string, mixed>> in standings order
     */
    private static function describeCars(EngineInput $input, EngineResult $result, array $teams): array
    {
        $rows = [];
        foreach ($result->cars as $state) {
            $row = $state->toArray();
            // Cast so an empty list is {} in JSON like a filled one, not [].
            $row['contagem_por_fonte'] = (object) $state->contagemPorFonte;
            $row['equipe'] = $teams[$state->carro]['equipe'] ?? null;
            $row['escola'] = $teams[$state->carro]['escola'] ?? null;

            $imported = [];
            foreach ($input->totals as $fonteId => $lapsByCar) {
                if (isset($lapsByCar[$state->carro])) {
                    $imported[$fonteId] = (int) $lapsByCar[$state->carro];
                }
            }
            $row['totais_importados'] = (object) $imported;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * For the "reatribuir para…" and "adicionar passagem" inputs.
     *
     * @param array<int, array{equipe: string, escola: string}> $teams
     * @return array<int, array{numero: int, equipe: ?string}>
     */
    private static function describeValidCars(EngineInput $input, array $teams): array
    {
        $cars = $input->validCars;
        sort($cars);
        $rows = [];
        foreach ($cars as $car) {
            $rows[] = ['numero' => $car, 'equipe' => $teams[$car]['equipe'] ?? null];
        }
        return $rows;
    }

    /**
     * @return array<int, array{equipe: string, escola: string}> car number => names
     */
    private static function teamsByNumber(Prova $prova): array
    {
        $teams = [];
        foreach (EquipeQuery::create()->filterByEventoId($prova->getEventoId())->find() as $equipe) {
            $teams[(int) $equipe->getEquipeId()] = [
                'equipe' => (string) $equipe->getEquipe(),
                'escola' => (string) $equipe->getEscola(),
            ];
        }
        return $teams;
    }

    /**
     * @param array<string, string[]> $watching
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
