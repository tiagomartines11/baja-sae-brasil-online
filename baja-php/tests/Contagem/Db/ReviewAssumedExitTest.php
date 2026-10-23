<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\InferredExits;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;

/**
 * "Saída presumida": an exit from the pit lane that the ENGINE assumed, as
 * the review screen and race control show it, and "Confirmar saída"
 * (round 4e).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The pit lane is logged by one person at the way in and another at the way
 * out. When the one at the way out misses a car, the log has the car inside
 * for the rest of the race. A single pass at the line while it is "inside"
 * is held (see ReviewInferredExitTest). But when two counters agree on TWO
 * passes of the car, a normal lap apart, the car is plainly racing: the
 * engine then assumes it left one second before the first of those passes
 * and counts both.
 *
 * That assumed exit is nowhere in the database. It exists in the result of
 * the calculation only. A person can turn it into a stored exit with
 * "Confirmar saída"; it is then an exit marked by that person, like the
 * ones of "Marcar saída do box".
 *
 * The race: start at 0:00. Car A crosses at 1:00, 3:00, 5:00 and 7:00 (three
 * laps of two minutes: the engine only assumes anything once it knows the
 * car's normal lap), is logged INTO the pit lane at 7:30 and never out, and
 * crosses again at 9:00 and 11:00, typed by all three counters each time.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewAssumedExitTest extends ReviewDbTestCase
{
    use RaceControlForms;

    /**
     * The moment the engine assumes the exit: one second before the pass at
     * 9:00. The three counters typed it at 9:00, 9:01 and 9:02, so the pass
     * time (their median) is 9:01 and the assumed exit is 9:00.
     */
    private const ASSUMED_EXIT_S = 540;

    /** @var int[] the entries of the pass at 9:00, the first after the missed exit */
    private array $firstPassOut = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startRace(self::RACE_START_MS);
        foreach ([60, 180, 300, 420] as $seconds) {
            $this->allCountersSee(self::CAR_A, $seconds);
        }
        $this->newRequest();
        $this->service->withLock(function (): void {
            $this->service->recordOcorrencia(Ocorrencia::BOX_ENTRADA, $this->at(450), self::CAR_A, 'zzt_box');
        });
        $this->firstPassOut = $this->allCountersSee(self::CAR_A, 540);
        $this->allCountersSee(self::CAR_A, 660);
        $this->syncAll($this->at(690));
        Clock::freezeAt($this->at(720));
        $this->recompute();
    }

    /**
     * SITUATION: as above. The reviewer opens the review screen.
     *
     * WHAT WENT WRONG: the assumed exit was invisible. The stay on the pit
     * lane simply ended at 9:00 with nothing to say why, as if somebody had
     * logged the car out.
     *
     * EXPECTED: the state lists the assumed exit (area, car, time, and the
     * entry of the stay it ends). The car's timeline has a mark for it on
     * the pit lane, flagged "presumida" and neither "inferida" (nobody
     * marked it) nor a row of the database (it has no id). The informational
     * flag "saida_presumida" is open, on the pass at 9:00. And the count
     * already includes both passes: 5 laps.
     */
    public function testAnAssumedExitIsInTheStateAndOnTheLaneMarkedAsAssumed(): void
    {
        $state = $this->freshScreen();

        $this->assertSame(5, $this->carRow($state, self::CAR_A)['voltas'], 'both passes after the missed exit count');
        $this->assertSame(
            [['area' => 'box', 'carro' => self::CAR_A, 'ts_ms' => $this->at(self::ASSUMED_EXIT_S), 'entrada_ms' => $this->at(450)]],
            $state['saidas_presumidas']
        );
        $flag = $this->flagOfType($state, 'saida_presumida');
        $this->assertNotNull($flag);
        $this->assertSame($this->at(self::ASSUMED_EXIT_S) + 1000, $flag['ts_ms'], 'the screen finds the exit one second before the flag\'s pass');

        $mark = $this->exitMarkOnTheLane();
        $this->assertNotNull($mark, 'the assumed exit is drawn on the pit lane');
        $this->assertSame($this->at(self::ASSUMED_EXIT_S), $mark['ts_ms']);
        $this->assertTrue($mark['presumida']);
        $this->assertFalse($mark['inferida']);
        $this->assertNull($mark['id'], 'it is not a stored event');
        $this->assertSame(0, $this->storedExits(), 'nothing was written for it');
    }

    /**
     * SITUATION: the reviewer presses "Confirmar saída" on the flag. The
     * screen sends the decision "marcar_saida" for the area and the moment
     * the engine assumed.
     *
     * WHAT WENT WRONG (what would, without care): the server checked "is
     * the car inside at that moment?" against the calculation, in which the
     * stay already ENDS at the assumed exit. It answered "not inside any
     * more" and refused to store the very exit it had assumed.
     *
     * EXPECTED: the exit is stored at 9:00, attributed to the reviewer
     * (origem "revisor"), with its decision in the history. From then on
     * the LOG ends the stay: the engine assumes nothing, the flag is gone,
     * and the lane shows the exit as marked by a reviewer ("inferida"), no
     * longer as assumed. THE COUNT DOES NOT CHANGE: 5 laps before, 5 after;
     * the passes were counted already.
     */
    public function testConfirmingStoresTheExitTheFlagGoesAwayAndTheCountDoesNotChange(): void
    {
        $before = $this->freshScreen();
        $flag = $this->flagOfType($before, 'saida_presumida');
        $lapsBefore = $this->carRow($before, self::CAR_A)['voltas'];

        $answer = $this->decide(
            ['tipo' => 'marcar_saida', 'area' => 'box', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(self::ASSUMED_EXIT_S)],
            $flag['passagem_ids'],
            $flag['assinatura']
        );

        $this->assertSame(200, $answer['status']);
        $after = $answer['body']['estado'];
        $this->assertSame($lapsBefore, $this->carRow($after, self::CAR_A)['voltas'], 'confirming an assumed exit changes no count');
        $this->assertSame(5, $lapsBefore);
        $this->assertSame([], $after['saidas_presumidas'], 'the log ends the stay now: nothing is assumed');
        $this->assertNull($this->flagOfType($after, 'saida_presumida'), 'the flag went away by itself');

        $this->assertSame('revisor', $this->fetchValue(
            'SELECT origem FROM contagem_ocorrencia WHERE evento_id = ? AND prova_id = ? AND tipo = ? AND ts_ms = ?',
            [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::BOX_SAIDA, $this->at(self::ASSUMED_EXIT_S)]
        ));
        $decision = $after['historico_em_vigor'][0];
        $this->assertSame([Decision::MARCAR_SAIDA, self::CAR_A, 'box', 'zzt_revisor'], [$decision['tipo'], $decision['equipe_numero'], $decision['motivo'], $decision['usuario']]);

        $mark = $this->exitMarkOnTheLane();
        $this->assertTrue($mark['inferida'], 'now an exit a reviewer marked');
        $this->assertFalse($mark['presumida']);

        // A second click (another reviewer) finds the log already has the exit.
        $again = $this->decide(['tipo' => 'marcar_saida', 'area' => 'box', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(self::ASSUMED_EXIT_S)]);
        $this->assertSame(409, $again['status']);
        $this->assertSame(1, $this->storedExits());
    }

    /**
     * SITUATION: race control. The controller sees the assumed exit listed
     * and presses "Confirmar saída" there. Later the confirmation turns out
     * to be a mistake and the controller presses "Cancelar" on it.
     *
     * EXPECTED: race control lists assumed exits apart from the exits
     * people marked. After "Confirmar saída" the exit is in the marked
     * list (with its "corrigir" and "cancelar") and no longer assumed.
     * "Cancelar" takes the stored exit back; the log has the car inside
     * again, so the engine ASSUMES the exit again: it is back in the
     * assumed list, the flag is back, and the car still has its 5 laps.
     * Nothing breaks, and the count never moved.
     */
    public function testRaceControlListsConfirmsAndCancellingBringsTheAssumptionBack(): void
    {
        $this->newRequest();
        $assumed = InferredExits::assumed($this->service);
        $this->assertCount(1, $assumed);
        $this->assertSame([], InferredExits::inForce($this->prova));

        $form = ['acao' => 'confirmar_saida', 'area' => 'box', 'carro' => (string) self::CAR_A, 'ts_ms' => (string) $assumed[0]['ts_ms']];
        $this->press($form);

        $this->newRequest();
        $this->assertSame([], InferredExits::assumed($this->service));
        $marked = InferredExits::inForce($this->prova);
        $this->assertCount(1, $marked);
        $this->assertSame(['box', self::CAR_A, $this->at(self::ASSUMED_EXIT_S), 'zzt_controle'], [
            $marked[0]['area'], $marked[0]['carro'], $marked[0]['ts_ms'], $marked[0]['usuario'],
        ]);
        $this->assertEquals(5, $this->publishedData(self::CAR_A)['VOLTAS'], 'the count did not move');
        $this->assertStringContainsString('já consta no registro', $this->refusalOf($form), 'it cannot be confirmed twice');

        $this->press(['acao' => 'cancelar_saida', 'decisao_id' => (string) $marked[0]['decisao_id']]);

        $this->newRequest();
        $this->assertSame([], InferredExits::inForce($this->prova));
        $this->assertCount(1, InferredExits::assumed($this->service), 'the engine assumes the exit again');
        $state = $this->freshScreen();
        $this->assertNotNull($this->flagOfType($state, 'saida_presumida'), 'and the flag is back');
        $this->assertSame(5, $this->carRow($state, self::CAR_A)['voltas']);
        $this->assertTrue($this->exitMarkOnTheLane()['presumida']);
    }

    public function testConfirmingAnExitThatNobodyAssumedAndThatTheLogDoesNotNeedIsRefused(): void
    {
        // Car B was never logged into the pit lane.
        $refusal = $this->refusalOf(['acao' => 'confirmar_saida', 'area' => 'box', 'carro' => (string) self::CAR_B, 'ts_ms' => (string) $this->at(400)]);
        $this->assertStringContainsString('já consta no registro', $refusal);

        $refusal = $this->refusalOf(['acao' => 'confirmar_saida', 'area' => 'garagem', 'carro' => (string) self::CAR_A, 'ts_ms' => (string) $this->at(400)]);
        $this->assertStringContainsString('inválida', $refusal);
        $this->assertSame(0, $this->storedExits());
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function flagOfType(array $state, string $type): ?array
    {
        foreach ($state['alertas_abertos'] as $flag) {
            if ($flag['tipo'] === $type) {
                return $flag;
            }
        }
        return null;
    }

    /** The exit of car A on the pit lane of its timeline, however it came to be there. */
    private function exitMarkOnTheLane(): ?array
    {
        $this->newRequest();
        foreach (TimelineWindow::forCar($this->service, self::CAR_A)['ocorrencias'] as $mark) {
            if ($mark['tipo'] === Ocorrencia::BOX_SAIDA) {
                return $mark;
            }
        }
        return null;
    }

    private function storedExits(): int
    {
        return $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::BOX_SAIDA]
        );
    }
}
