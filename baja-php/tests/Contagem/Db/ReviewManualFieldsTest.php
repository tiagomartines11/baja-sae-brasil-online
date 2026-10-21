<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\ManualScoreFields;
use Baja\Contagem\Service\Clock;
use Baja\Model\InputQuery;

/**
 * The old score entry page (juiz/entry.php and save_entry.php) on a laps
 * prova.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Each car has ONE score row per prova. In a laps prova that row holds two
 * kinds of field:
 *
 *   - VOLTAS, LAST and BEST, written by the lap counting many times a
 *     minute while the race runs;
 *   - fields a judge types on the entry page, such as DESCLAS
 *     (disqualified: "SIM" or "NÃO").
 *
 * The entry page was written long before lap counting existed and treated
 * the row as its own. These tests are about the two ways that destroyed
 * lap counts. The page now hands the work to ManualScoreFields, which is
 * what is called here.
 *
 * The test prova scores like a real endurance race: the leader gets 400
 * points, a disqualified car gets 0.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewManualFieldsTest extends ReviewDbTestCase
{
    /**
     * SITUATION: car A has completed 2 laps and was marked disqualified by
     * mistake. The judge opens the car on the entry page and presses the
     * button that used to be called "Deletar Nota" to take the mark away.
     *
     * WHAT WENT WRONG: the button deleted the whole row. The car's laps
     * vanished from the results until the next pass was typed, and the
     * disqualification of a car that was no longer passing was replaced by
     * "this car never ran".
     *
     * EXPECTED: on a laps prova the button clears only the fields people
     * type. The row stays, VOLTAS is still 2, DESCLAS is empty, and the
     * points are recalculated: the car is back to the leader's 400.
     */
    public function testClearingACarsManualFieldsKeepsItsLaps(): void
    {
        $car = self::CAR_A;
        $this->carCompletesTwoLaps($car);
        $this->setManualField($car, 'DESCLAS', 'SIM');
        $this->recomputePoints();
        $this->assertEquals(0, $this->publishedPoints($car)['ENDPTS'], 'disqualified: no points');

        $this->newRequest();
        $cleared = ManualScoreFields::clear($this->prova, $car, ['DESCLAS']);

        $this->assertTrue($cleared);
        $row = $this->publishedData($car);
        $this->assertNotNull($row, 'the row must not be deleted');
        $this->assertEquals(2, $row['VOLTAS']);
        $this->assertNull($row['DESCLAS']);
        $this->assertEquals(400, $this->publishedPoints($car)['ENDPTS']);
    }

    /**
     * SITUATION: car A has 1 lap. A judge opens the entry page for car A:
     * the page reads the row (VOLTAS = 1). While the judge is choosing
     * DESCLAS = "NÃO", the car passes again and the lap counting publishes
     * VOLTAS = 2. Then the judge presses "Salvar".
     *
     * WHAT WENT WRONG: the page wrote back the whole row as it had read it,
     * with VOLTAS = 1. The lap just published was overwritten, and the
     * results showed one lap fewer until the car's next pass.
     *
     * EXPECTED: the save takes the same lock the lap counting uses, reads
     * the row AGAIN inside it, and changes only the judge's field: VOLTAS
     * is 2 and DESCLAS is "NÃO". Even a VOLTAS value sent by the page (an
     * old page, or somebody tampering) is ignored.
     */
    public function testSavingAManualFieldDoesNotPutAnOldLapCountBack(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee($car, 60);
        $this->allCountersSee($car, 160);
        $this->syncAll($this->at(170));
        Clock::freezeAt($this->at(180));
        $this->recompute();

        // The entry page loads the row to draw the form.
        $rowAsThePageReadIt = InputQuery::create()
            ->filterByEventoId(self::EVENTO_ID)
            ->filterByProvaId(self::PROVA_ID)
            ->findOneByEquipeId($car);
        $this->assertEquals(1, $rowAsThePageReadIt->getDados()->VOLTAS);

        // Meanwhile the car passes again and the new lap is published.
        $this->allCountersSee($car, 260);
        $this->syncAll($this->at(270));
        Clock::freezeAt($this->at(280));
        $this->recompute();
        $this->assertEquals(2, $this->publishedData($car)['VOLTAS']);

        // The judge presses "Salvar".
        $this->newRequest();
        $changed = ManualScoreFields::save($this->prova, $car, ['DESCLAS' => 'NÃO', 'VOLTAS' => 1.0]);

        $this->assertSame(['DESCLAS' => 'NÃO'], $changed, 'only the judge\'s field was changed');
        $row = $this->publishedData($car);
        $this->assertEquals(2, $row['VOLTAS'], 'the lap published in the meantime is still there');
        $this->assertSame('NÃO', $row['DESCLAS']);
    }

    /**
     * SITUATION: a judge opens a car that has not passed yet (it has no
     * score row), changes nothing and presses "Salvar".
     *
     * EXPECTED: no row is created. An empty row would list the car on the
     * results page as if it had taken part.
     */
    public function testSavingNothingForACarWithoutARowCreatesNoRow(): void
    {
        $this->startRace(self::RACE_START_MS);

        $changed = ManualScoreFields::save($this->prova, self::CAR_C, ['DESCLAS' => null]);

        $this->assertSame([], $changed);
        $this->assertNull($this->publishedData(self::CAR_C));
    }

    /**
     * SITUATION: the count runs beside the spreadsheet (publish mode
     * "desligada"): the spreadsheet sends VOLTAS through juiz/remote.php.
     * Car A has VOLTAS = 9 from it, and a judge marked car A as
     * disqualified and car B as fit to race (APTO = SIM, the prova's
     * classification field). Before a new session the spreadsheet sends
     * "truncate": start my totals again from nothing.
     *
     * WHAT WENT WRONG: "truncate" deleted every score row of the prova. The
     * disqualification was gone, and so was APTO: by the rule "blank means
     * not classified" every car silently left the race, and nothing
     * recounted.
     *
     * EXPECTED: on a laps prova only the three fields the spreadsheet owns
     * are emptied. The rows stay, with DESCLAS and APTO as they were, and
     * car B is still classified.
     */
    public function testTheSpreadsheetsTruncateEmptiesItsOwnFieldsAndKeepsTheRows(): void
    {
        $this->addClassificationField();
        $this->changeSettings(['modo_publicacao' => 'desligada', 'campo_classificacao' => 'APTO']);
        $this->setManualField(self::CAR_A, 'VOLTAS', 9);
        $this->setManualField(self::CAR_A, 'DESCLAS', 'SIM');
        $this->setManualField(self::CAR_B, 'APTO', 'SIM');

        $this->newRequest();
        $emptied = ManualScoreFields::truncateSpreadsheetFields($this->prova);

        $this->assertTrue($emptied);
        $carA = $this->publishedData(self::CAR_A);
        $this->assertNotNull($carA, 'the row must not be deleted');
        $this->assertNull($carA['VOLTAS']);
        $this->assertSame('SIM', $carA['DESCLAS']);
        $this->assertSame('SIM', $this->publishedData(self::CAR_B)['APTO']);
        $this->newRequest();
        $this->assertSame([self::CAR_B], array_keys($this->service->carRoster()->classified()), 'car B still takes part');
    }

    /**
     * SITUATION: the lap counting publishes this prova (mode "confirmadas")
     * and car A has 2 laps. An old spreadsheet, left running, sends
     * "truncate".
     *
     * EXPECTED: refused, and nothing changes: VOLTAS is the count's while
     * it publishes, and "Zerar corrida" is the way to empty a race.
     */
    public function testTheSpreadsheetsTruncateIsRefusedWhileTheCountPublishes(): void
    {
        $this->carCompletesTwoLaps(self::CAR_A);

        $this->newRequest();
        $emptied = ManualScoreFields::truncateSpreadsheetFields($this->prova);

        $this->assertFalse($emptied);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /** Adds the SIM/NÃO field APTO to the test prova, as an organiser would in its parameters. */
    private function addClassificationField(): void
    {
        $params = json_decode((string) $this->fetchValue(
            'SELECT params FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, self::PROVA_ID]
        ), true);
        $params['inputs'][] = ['code' => 'APTO', 'name' => 'Apto para o enduro', 'type' => 'enum', 'options' => ['SIM', 'NÃO']];
        $this->executeSql(
            'UPDATE prova SET params = ? WHERE evento_id = ? AND prova_id = ?',
            [json_encode($params), self::EVENTO_ID, self::PROVA_ID]
        );
        $this->newRequest();
    }

    /** Three crossings recorded by every counter: the pass zero and two laps. */
    private function carCompletesTwoLaps(int $car): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee($car, 60);
        $this->allCountersSee($car, 160);
        $this->allCountersSee($car, 260);
        $this->syncAll($this->at(270));
        Clock::freezeAt($this->at(280));
        $this->recompute();
        $this->assertEquals(2, $this->publishedData($car)['VOLTAS']);
    }

    /** Recalculates the points after a field was written straight into the database. */
    private function recomputePoints(): void
    {
        $this->newRequest();
        $this->prova->refreshVarsAndPontos();
    }
}
