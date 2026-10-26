<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api\Resultados;

use Baja\Api\Resultados\EventoEndpoint;
use Baja\Model\Evento;
use Baja\Model\Prova;
use Baja\Model\Resultado;
use PHPUnit\Framework\TestCase;

/**
 * What evento.php says about an event: its status, and what else can be
 * fetched about it.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The results site's menu is a JSON list the organisers write per event
 * (column evento.menu). Each item is either a resultado id, or an object
 * {"Group name": [ids...]} that the site draws as a submenu. A value with
 * "php" in it is a link to some other page (a queue, the official clock),
 * not a resultado. evento.php lists the menu's resultados in that order, so
 * a consumer sees the same structure the public site has.
 *
 * Each resultado is made of one or more provas (its "inputs"). The provas
 * are listed too, with their type: "tournament" (a bracket) and "laps" (a
 * race counted by the lap counting, which has a live endpoint) are the two
 * a broadcast overlay cares about.
 *
 * Two flags of an event are easy to confuse:
 *
 *   finalizado     the event is over and its results are official. This is
 *                  the only flag that says "live or finished": spoilers and
 *                  cache times follow it. The API calls it "finished".
 *   em_andamento   despite its name ("in progress"), only the event the
 *                  results site opens when no event is named (bootstrap.php);
 *                  one event has it. The API calls it "default_event". A
 *                  finished event can have it, a running one can lack it.
 *
 * Everything here is built in memory: Propel objects can be created and
 * filled without a database.
 */
final class EventoShapeTest extends TestCase
{
    private static function evento(bool $finished = true, bool $spoilers = false, bool $default = false): Evento
    {
        return (new Evento())
            ->setEventoId('26BR')
            ->setNome('31ª Competição Baja SAE BRASIL')
            ->setAno(2026)
            ->setAtivo(true)
            ->setEmAndamento($default)
            ->setFinalizado($finished)
            ->setSpoilers($spoilers)
            ->setMenu([
                ['Segurança' => ['26BR_SGE', '26BR_NOT']],
                ['Enduro' => ['26BR_END']],
                ['Fila' => ['Fila Segurança' => 'fila.php?id=F1']],
                ['Relógio' => 'clock.php'],
                '26BR_SPR',
                '26BR_GER',
                '26BR_SGE',
            ]);
    }

    private static function resultado(string $id, string $name, array $inputs, string $colunas = '{"colunas": []}'): Resultado
    {
        return (new Resultado())->setResultadoId($id)->setNome($name)->setInputs($inputs)->setColunas(json_decode($colunas));
    }

    private static function prova(string $id, string $name, ?string $type, string $status = 'Final'): Prova
    {
        return (new Prova())->setProvaId($id)->setNome($name)->setStatus($status)->setParams($type === null ? (object)[] : (object)['type' => $type]);
    }

    private static function shape(Evento $evento): array
    {
        $resultados = [
            '26BR_SGE' => self::resultado('26BR_SGE', 'Segurança Estática', ['SGE']),
            '26BR_END' => self::resultado('26BR_END', 'Enduro', ['END', 'EPN']),
            '26BR_SPR' => self::resultado('26BR_SPR', 'Super Prime', ['SPR'], '{"type": "tournament"}'),
            '26BR_GER' => self::resultado('26BR_GER', 'Geral', ['SGE', 'END']),
            // Not in the menu: not listed.
            '26BR_TST' => self::resultado('26BR_TST', 'Teste', ['TST']),
        ];
        $provas = [
            'SGE' => self::prova('SGE', 'Segurança Estática', null),
            'END' => self::prova('END', 'Enduro', 'laps', 'Parcial'),
            'EPN' => self::prova('EPN', 'Penalizações Enduro', 'rolling'),
            'SPR' => self::prova('SPR', 'Super Prime', 'tournament'),
            'TST' => self::prova('TST', 'Teste', null),
        ];
        return EventoEndpoint::shape($evento, $resultados, $provas);
    }

    // --- The menu --------------------------------------------------------

    /**
     * In menu order, with the group each one is under (null at the top
     * level). Left out: "26BR_NOT", which is no resultado of the event; the
     * two links to pages; and the second "26BR_SGE".
     */
    public function testResultadosAreTheMenusInMenuOrder(): void
    {
        $resultados = self::shape(self::evento())['resultados'];

        $this->assertSame(['26BR_SGE', '26BR_END', '26BR_SPR', '26BR_GER'], array_column($resultados, 'id'));
        $this->assertSame(['Segurança', 'Enduro', null, null], array_column($resultados, 'group'));
    }

    /** A tournament is fetched from torneio.php, any other table from resultado.php. */
    public function testEachResultadoSaysWhatItIsAndWhereToFetchIt(): void
    {
        $resultados = self::shape(self::evento())['resultados'];

        $this->assertSame([
            'id' => '26BR_END',
            'name' => 'Enduro',
            'group' => 'Enduro',
            'type' => 'table',
            'provas' => ['END', 'EPN'],
            'url' => '/resultados/resultado.php?evt=26BR&id=26BR_END',
        ], $resultados[1]);
        $this->assertSame('tournament', $resultados[2]['type']);
        $this->assertSame('/resultados/torneio.php?evt=26BR&id=26BR_SPR', $resultados[2]['url']);
    }

    /** The menu walk on its own, with the shapes the seed's menus have. */
    public function testMenuEntriesSkipLinksAndKeepGroups(): void
    {
        $menu = json_decode('[{"Projeto": ["22BR_REL"]}, {"Enduro": {"1": "22BR_GRI", "2": "22BR_END"}}, {"Fila": {"Fila 1": "fila.php"}}, "22BR_GER"]');

        $this->assertSame(
            [['Projeto', '22BR_REL'], ['Enduro', '22BR_GRI'], ['Enduro', '22BR_END'], [null, '22BR_GER']],
            EventoEndpoint::menuEntries($menu)
        );
        $this->assertSame([], EventoEndpoint::menuEntries(null));
    }

    // --- The provas ------------------------------------------------------

    /**
     * The provas of the listed resultados, each once, in the order they
     * first appear. TST is not listed: its resultado is not in the menu.
     * Only the lap-counted prova has a live race to fetch.
     */
    public function testProvasSayWhichAreTournamentsAndWhichAreLapCounted(): void
    {
        $provas = self::shape(self::evento())['provas'];

        $this->assertSame(['SGE', 'END', 'EPN', 'SPR'], array_column($provas, 'id'));
        $this->assertSame([
            'id' => 'END',
            'name' => 'Enduro',
            'type' => 'laps',
            'status' => 'partial',
            'tournament' => false,
            'lap_counted' => true,
            'live_url' => '/resultados/corrida.php?evt=26BR&p=END',
        ], $provas[1]);
        $this->assertSame(['normal', 'laps', 'rolling', 'tournament'], array_column($provas, 'type'));
        $this->assertSame([false, false, false, true], array_column($provas, 'tournament'));
        $this->assertSame([null, '/resultados/corrida.php?evt=26BR&p=END', null, null], array_column($provas, 'live_url'));
    }

    // --- The status ------------------------------------------------------

    public function testAFinishedEvent(): void
    {
        $json = self::shape(self::evento(finished: true, spoilers: true));

        // Spoilers stay switched on after the event, but no longer apply.
        $this->assertSame(['default_event' => false, 'finished' => true, 'spoilers_active' => false], $json['status']);
        $this->assertSame('26BR', $json['event']['id']);
        $this->assertSame(2026, $json['event']['year']);
        $this->assertTrue($json['event']['listed']);
        $this->assertSame('/resultados/equipes.php?evt=26BR', $json['teams_url']);
    }

    /** "spoilers_active" follows the builder's rule (ViewerContext::publicView). */
    public function testARunningEventWithSpoilers(): void
    {
        $json = self::shape(self::evento(finished: false, spoilers: true));

        $this->assertSame(['default_event' => false, 'finished' => false, 'spoilers_active' => true], $json['status']);
    }

    public function testARunningEventWithoutSpoilers(): void
    {
        $this->assertFalse(self::shape(self::evento(finished: false, spoilers: false))['status']['spoilers_active']);
    }

    /**
     * em_andamento is only "the site's default event". It is reported as
     * such and changes nothing else: a finished default event is still
     * finished, with no spoilers; a running event that is not the default
     * still has them.
     */
    public function testTheDefaultEventFlagSaysOnlyThat(): void
    {
        $this->assertSame(
            ['default_event' => true, 'finished' => true, 'spoilers_active' => false],
            self::shape(self::evento(finished: true, spoilers: true, default: true))['status']
        );
        $this->assertSame(
            ['default_event' => false, 'finished' => false, 'spoilers_active' => true],
            self::shape(self::evento(finished: false, spoilers: true, default: false))['status']
        );
    }
}
