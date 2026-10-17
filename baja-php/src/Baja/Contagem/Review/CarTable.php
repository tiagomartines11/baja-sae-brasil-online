<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\CarState;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;

/**
 * The car table of the review screen, and the car list behind every
 * "número do carro" field.
 *
 * Besides what the engine worked out for each car, a row says what the car
 * IS to this race, because the screen words it differently:
 *
 *   classificado  present at the event AND classified for this prova: it
 *                 takes part
 *   presente      marked present at the event (classified or not)
 *   inscrito      a team of this event has that number, present or not
 *
 * A number that is not even "inscrito" is a typo. A number that is
 * "inscrito" but not "presente" is a real team nobody marked as present,
 * and calling that "número desconhecido" sends the reviewer looking for a
 * typing mistake that is not there.
 */
final class CarTable
{
    /**
     * The rows, without each car's status: "atrasado" is a statement about
     * this very second and is added by StateSnapshot::live().
     *
     * @param array<int, array{numero: int, nome: string, escola: string}> $teams every team of the event, present or not
     * @return array<int, array<string, mixed>> in standings order
     */
    public static function describe(EngineInput $input, EngineResult $result, array $teams): array
    {
        $officialPassesByCar = [];
        foreach ($result->laps as $lap) {
            if ($lap->isOfficialPass()) {
                $officialPassesByCar[$lap->carro] = ($officialPassesByCar[$lap->carro] ?? 0) + 1;
            }
        }

        $classified = array_fill_keys($input->validCars, true);
        $unclassified = array_fill_keys($input->unclassifiedCars, true);

        $rows = [];
        foreach ($result->cars as $state) {
            $row = $state->toArray();
            unset($row['status'], $row['status_desde_ms']);
            // Official passes are laps plus the car's pass zero. A counter
            // types passes, so this is what each counter's own number is
            // compared with.
            $row['passagens'] = $officialPassesByCar[$state->carro] ?? 0;
            // Takes part in the race: present at the event AND classified
            // for this prova. A car that is not has entries only by mistake
            // (or by a stale list on a phone); the table shows it apart.
            $row['classificado'] = isset($classified[$state->carro]);
            $row['presente'] = $row['classificado'] || isset($unclassified[$state->carro]);
            $row['inscrito'] = isset($teams[$state->carro]);
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
            unset($unclassified[$state->carro]);
        }

        // The engine keeps a car that is not classified out of the
        // standings altogether. It is listed all the same, after the
        // others and with nothing counted, so that a reviewer can open its
        // timeline and see what was typed for it.
        ksort($unclassified);
        foreach (array_keys($unclassified) as $carro) {
            $row = (new CarState($carro))->toArray();
            unset($row['status'], $row['status_desde_ms']);
            $rows[] = $row + [
                'passagens' => 0,
                'equipe' => $teams[$carro]['nome'] ?? null,
                'escola' => $teams[$carro]['escola'] ?? null,
                'classificado' => false,
                'presente' => true,
                'inscrito' => true,
                'totais_importados' => [],
            ];
        }
        return $rows;
    }

    /**
     * True when no team at all is marked present at the event. Every
     * number typed is then "not a present team", which says nothing about
     * the numbers and everything about the attendance list: the screen
     * shows ONE warning about that instead of a label on every car.
     */
    public static function nobodyIsPresent(EngineInput $input): bool
    {
        return $input->validCars === [] && $input->unclassifiedCars === [];
    }

    /**
     * For the "reatribuir para…" and "adicionar passagem" inputs.
     *
     * @param array<int, array{numero: int, nome: string, escola: string}> $teams
     * @return array<int, array{numero: int, equipe: ?string}>
     */
    public static function describeValidCars(EngineInput $input, array $teams): array
    {
        $cars = $input->validCars;
        sort($cars);
        $rows = [];
        foreach ($cars as $car) {
            $rows[] = ['numero' => $car, 'equipe' => $teams[$car]['nome'] ?? null];
        }
        return $rows;
    }
}
