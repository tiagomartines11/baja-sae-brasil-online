<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api\Resultados;

use Baja\Api\Resultados\CorridaEndpoint;
use Baja\Model\Evento;
use Baja\Model\Prova;
use PHPUnit\Framework\TestCase;

/**
 * How corrida.php writes a live race.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The endurance race ("Enduro") is a prova of type "laps": people at the
 * line type the number of every car that passes, and the lap counting
 * (Baja\Contagem, docs/contagem.md) works out each car's laps from that.
 * Working that out is expensive, so it is done every few seconds at most,
 * and the result is kept in a "snapshot". The public feed (PublicFeed,
 * also resultados/contagem_feed.php) only reads that snapshot; it never
 * recalculates.
 *
 * corrida.php asks PublicFeed for the feed and renames its fields: the
 * feed speaks Portuguese and may change, the API speaks English and is a
 * fixed contract for other programs (docs/results-api.md). The numbers
 * themselves are passed on untouched. These tests give shape() a feed
 * written by hand, in exactly the form PublicFeed produces, and check the
 * renaming.
 *
 * All times are milliseconds since 1970, UTC.
 */
final class CorridaShapeTest extends TestCase
{
    private const START_MS = 1774789261000;

    /** A race 30 minutes in: car 12 leads with 10 laps, car 7 is in the pit lane. */
    private static function feed(): array
    {
        return [
            'evento_id' => '26BR',
            'prova_id' => 'END',
            'gerado_em_ms' => self::START_MS + 1800000,
            'calculado_em_ms' => self::START_MS + 1798000,
            'simulacao' => false,
            'corrida' => ['largada_ms' => self::START_MS, 'encerramento_ms' => null, 'estado' => 'correndo', 'decorrido_ms' => 1800000],
            'ritmo' => ['volta_mediana_ms' => 171000, 'voltas_na_janela' => 40, 'janela_s' => 900],
            'carros' => [
                [
                    'posicao' => 1, 'numero' => 12, 'equipe' => 'Equipe 12', 'escola' => 'Escola 12', 'voltas' => 10,
                    'ultima_passagem_ms' => self::START_MS + 1795000, 'ultima_volta_ms' => 170000, 'melhor_volta_ms' => 165000,
                    'volta_esperada_ms' => 172000, 'status' => 'correndo', 'status_desde_ms' => null,
                    'box' => ['dentro' => false, 'desde_ms' => null, 'paradas' => 0, 'total_ms' => 0, 'ultima_ms' => null],
                    'abastecimento' => null,
                ],
                [
                    'posicao' => 2, 'numero' => 7, 'equipe' => 'Equipe 7', 'escola' => 'Escola 7', 'voltas' => 9,
                    'ultima_passagem_ms' => self::START_MS + 1700000, 'ultima_volta_ms' => 180000, 'melhor_volta_ms' => 175000,
                    'volta_esperada_ms' => 181000, 'status' => 'no_box', 'status_desde_ms' => self::START_MS + 1750000,
                    'box' => ['dentro' => true, 'desde_ms' => self::START_MS + 1750000, 'paradas' => 1, 'total_ms' => 110000, 'ultima_ms' => 60000],
                    'abastecimento' => null,
                ],
            ],
            'melhor_volta' => ['numero' => 12, 'equipe' => 'Equipe 12', 'escola' => 'Escola 12', 'volta' => 4, 'tempo_volta_ms' => 165000, 'ts_ms' => self::START_MS + 700000],
            'novas_melhores_voltas' => [
                ['numero' => 7, 'equipe' => 'Equipe 7', 'escola' => 'Escola 7', 'volta' => 1, 'tempo_volta_ms' => 180000, 'ts_ms' => self::START_MS + 200000],
                ['numero' => 12, 'equipe' => 'Equipe 12', 'escola' => 'Escola 12', 'volta' => 4, 'tempo_volta_ms' => 165000, 'ts_ms' => self::START_MS + 700000],
            ],
            'ultimas_paradas' => [
                ['area' => 'box', 'tipo' => 'box_entrada', 'carro' => 7, 'ts_ms' => self::START_MS + 1750000, 'inferida' => false, 'presumida' => false],
            ],
        ];
    }

    private static function shape(array $feed, ?int $tempo = 1774789261, string $status = 'Parcial'): array
    {
        $prova = (new Prova())->setProvaId('END')->setNome('Enduro')->setStatus($status)->setTempo($tempo);
        return CorridaEndpoint::shape((new Evento())->setEventoId('26BR'), $prova, $feed);
    }

    // --- The race clock --------------------------------------------------

    /**
     * Everything an overlay needs to run a race clock: the state, when the
     * race started, how long it has been running as of server_time_ms, and
     * the planned length, which no prova records yet (null).
     */
    public function testTheClock(): void
    {
        $this->assertSame([
            'state' => 'running',
            'start_ms' => self::START_MS,
            'finish_ms' => null,
            'elapsed_ms' => 1800000,
            'server_time_ms' => self::START_MS + 1800000,
            'planned_duration_ms' => null,
            // prova.tempo, in seconds in the database, as milliseconds here.
            'scoring_start_ms' => self::START_MS,
            'prova_status' => 'partial',
        ], self::shape(self::feed())['clock']);
    }

    public function testEachRaceStateHasAnEnglishName(): void
    {
        $states = [];
        foreach (['aguardando', 'correndo', 'bandeira_vermelha', 'encerrada'] as $estado) {
            $feed = self::feed();
            $feed['corrida']['estado'] = $estado;
            $states[] = self::shape($feed)['clock']['state'];
        }
        $this->assertSame(['waiting', 'running', 'red_flag', 'finished'], $states);
    }

    // --- The cars --------------------------------------------------------

    public function testACar(): void
    {
        $this->assertSame([
            'position' => 2,
            'number' => 7,
            'team' => 'Equipe 7',
            'school' => 'Escola 7',
            'laps' => 9,
            'last_pass_ms' => self::START_MS + 1700000,
            'last_lap_ms' => 180000,
            'best_lap_ms' => 175000,
            'expected_lap_ms' => 181000,
            'status' => 'in_pit',
            'status_since_ms' => self::START_MS + 1750000,
            'pit' => ['inside' => true, 'since_ms' => self::START_MS + 1750000, 'stops' => 1, 'total_ms' => 110000, 'last_ms' => 60000],
            'fueling' => null,
        ], self::shape(self::feed())['cars'][1]);
    }

    /** The cars keep the feed's order, which is the standings. */
    public function testCarsKeepTheFeedsOrder(): void
    {
        $this->assertSame([12, 7], array_column(self::shape(self::feed())['cars'], 'number'));
    }

    public function testEachCarStatusHasAnEnglishName(): void
    {
        $this->assertSame(
            ['running', 'late', 'stopped', 'in_pit', 'fueling', 'out'],
            array_map(
                fn(string $status) => CorridaEndpoint::rename($status, CorridaEndpoint::CAR_STATUSES),
                ['correndo', 'atrasado', 'parado', 'no_box', 'abastecendo', 'fora']
            )
        );
    }

    /**
     * If the lap counting one day adds a status, the API passes it on as
     * it is rather than dropping it: a consumer then sees something it does
     * not know, instead of a car with no status.
     */
    public function testAStatusTheApiDoesNotKnowPassesThrough(): void
    {
        $feed = self::feed();
        $feed['carros'][0]['status'] = 'na_vistoria';

        $this->assertSame('na_vistoria', self::shape($feed)['cars'][0]['status']);
    }

    // --- Best laps, stops and pace ---------------------------------------

    public function testBestLapAndItsHistory(): void
    {
        $json = self::shape(self::feed());

        $this->assertSame(
            ['number' => 12, 'team' => 'Equipe 12', 'school' => 'Escola 12', 'lap' => 4, 'lap_time_ms' => 165000, 'at_ms' => self::START_MS + 700000],
            $json['best_lap']
        );
        $this->assertSame([180000, 165000], array_column($json['best_lap_history'], 'lap_time_ms'));
    }

    public function testRecentStops(): void
    {
        $this->assertSame(
            [['area' => 'pit', 'event' => 'enter', 'number' => 7, 'at_ms' => self::START_MS + 1750000, 'inferred' => false, 'assumed' => false]],
            self::shape(self::feed())['recent_stops']
        );
    }

    public function testThePace(): void
    {
        $this->assertSame(['median_lap_ms' => 171000, 'laps_in_window' => 40, 'window_s' => 900], self::shape(self::feed())['pace']);
    }

    // --- Before anything happened ----------------------------------------

    /**
     * A laps prova whose race was never started: the feed has no cars and
     * no start. The answer still has every key, with nulls and empty
     * lists, so a consumer never has to check whether a key exists. This
     * is what the dev seed's 26BR Enduro gives.
     */
    public function testARaceThatHasNotStartedIsWellFormed(): void
    {
        $feed = [
            'evento_id' => '26BR', 'prova_id' => 'END', 'gerado_em_ms' => 1792889708152, 'calculado_em_ms' => null,
            'simulacao' => false,
            'corrida' => ['largada_ms' => null, 'encerramento_ms' => null, 'estado' => 'aguardando', 'decorrido_ms' => null],
            'ritmo' => null, 'carros' => [], 'melhor_volta' => null, 'novas_melhores_voltas' => [], 'ultimas_paradas' => [],
        ];
        $json = self::shape($feed, tempo: null, status: 'Final');

        $this->assertSame('waiting', $json['clock']['state']);
        $this->assertNull($json['clock']['start_ms']);
        $this->assertNull($json['clock']['elapsed_ms']);
        $this->assertNull($json['clock']['scoring_start_ms']);
        $this->assertSame('final', $json['clock']['prova_status']);
        $this->assertNull($json['calculated_at_ms']);
        $this->assertNull($json['pace']);
        $this->assertSame([], $json['cars']);
        $this->assertNull($json['best_lap']);
        $this->assertSame([], $json['best_lap_history']);
        $this->assertSame([], $json['recent_stops']);
    }
}
