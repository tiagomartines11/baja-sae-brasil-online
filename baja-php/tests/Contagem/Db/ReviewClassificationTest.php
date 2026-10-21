<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\ManualScoreFields;
use Baja\Contagem\Review\SourceSettings;
use Baja\Contagem\Service\Clock;

/**
 * Classified cars, and the fields of a laps prova that people type.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A prova may name one of its own fields as the "classification field"
 * (here APTO, filled with SIM or NÃO per car on the ordinary entry page).
 * A car then takes part in the race only when it is present at the event
 * AND its APTO is SIM. Blank counts as not classified.
 *
 * A pass typed for a car that is not classified is never thrown away: it is
 * stored, shown to the reviewer with a flag, and does not count unless the
 * reviewer says "contar mesmo assim".
 *
 * The same entry page also shows VOLTAS, LAST and BEST. While the count
 * publishes, those three are the engine's and the page must not write them.
 * With publishing switched off ("desligada") the engine writes nothing and
 * a person fills them in.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewClassificationTest extends ReviewDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addInputFieldToTheProva('APTO');
        $this->changeSettings(['campo_classificacao' => 'APTO']);
    }

    /**
     * SITUATION: cars A and B are classified (APTO = SIM). Car C is at the
     * event but failed a check: its APTO is blank. Each car crosses twice,
     * typed by all three counters (a counter's phone can have a stale car
     * list, and people type what they see).
     *
     * EXPECTED on the review screen:
     *   - the car table marks A and B as classified and C as present but
     *     not classified; C has no laps although its passes were typed;
     *   - each pass of C raises a flag "carro_nao_classificado" that says
     *     confirming it would add a pass;
     *   - only classified cars are offered where a car number is asked for.
     */
    public function testTheCarTableSaysWhichCarsTakePartAndPassesOfTheOthersAreFlagged(): void
    {
        $this->raceWhereEveryCarCrossesTwice();

        $state = $this->freshScreen();

        $this->assertTrue($this->carRow($state, self::CAR_A)['classificado']);
        $this->assertSame(1, $this->carRow($state, self::CAR_A)['voltas']);
        $rowOfC = $this->carRow($state, self::CAR_C);
        $this->assertFalse($rowOfC['classificado']);
        $this->assertTrue($rowOfC['presente']);
        $this->assertSame(0, $rowOfC['voltas']);

        $flagsOfC = $this->flagsOfType($state, 'carro_nao_classificado');
        $this->assertCount(2, $flagsOfC, 'one flag per pass typed for car C');
        $this->assertSame([self::CAR_C], $flagsOfC[0]['carros']);
        $this->assertSame('mais_uma_passagem', $flagsOfC[0]['efeito_de_confirmar']);
        $this->assertSame([self::CAR_A, self::CAR_B], array_column($state['carros_validos'], 'numero'));
    }

    /**
     * SITUATION: the same race. The stewards decide car C may race after
     * all, and a judge sets its APTO to SIM on the entry page, in the
     * middle of the race. Later they change their minds and set it to NÃO.
     *
     * WHAT WOULD GO WRONG: the entry page only wrote the field. The count
     * of car C would stay at zero until the next upload happened to
     * recount that car, and its flags would stay open until the next full
     * recalculation.
     *
     * EXPECTED: saving a different value in the classification field
     * recounts the race in the same step. Car C has its lap at once, with
     * points; set back to NÃO, it has none again. Saving another field
     * (DESCLAS) does not recount anything.
     */
    public function testChangingACarsClassificationRecountsTheRaceAtOnce(): void
    {
        $this->raceWhereEveryCarCrossesTwice();
        $this->recompute();
        $this->assertEquals(0, $this->publishedData(self::CAR_C)['VOLTAS'] ?? 0);

        $this->saveOnTheEntryPage(self::CAR_C, ['APTO' => 'SIM', 'DESCLAS' => null]);

        $this->assertEquals(1, $this->publishedData(self::CAR_C)['VOLTAS']);
        $this->assertSame([], $this->flagsOfType($this->screenWithoutRecalculating(), 'carro_nao_classificado'));

        $this->saveOnTheEntryPage(self::CAR_C, ['APTO' => 'NÃO', 'DESCLAS' => null]);
        $this->assertEquals(0, $this->publishedData(self::CAR_C)['VOLTAS']);

        $changesBefore = $this->screenWithoutRecalculating()['calculado_em_ms'];
        Clock::freezeAt($this->at(400));
        $this->saveOnTheEntryPage(self::CAR_A, ['APTO' => 'SIM', 'DESCLAS' => 'NÃO']);
        $this->assertSame($changesBefore, $this->screenWithoutRecalculating()['calculado_em_ms'], 'DESCLAS alone recalculates nothing');
    }

    /**
     * SITUATION: the lap counting runs beside the old spreadsheet for a
     * trial: the publish mode is "desligada", so the engine writes nothing
     * to the official results. A judge copies VOLTAS = 7 for car A from the
     * spreadsheet onto the entry page.
     *
     * WHAT WENT WRONG: the entry page treated VOLTAS, LAST and BEST as the
     * engine's in every mode: it showed them as plain text and dropped them
     * from what it saved. In "desligada" nobody could type them and the
     * engine did not write them either.
     *
     * EXPECTED: in "desligada" the three fields are ordinary fields: the
     * value typed is saved. With publishing on they belong to the engine
     * again and a value sent for them is ignored.
     */
    public function testWithPublishingOffTheLapFieldsCanBeTypedByHand(): void
    {
        $this->changeSettings(['modo_publicacao' => 'desligada']);
        $this->assertSame([], ManualScoreFields::engineOwnedFields($this->prova));

        $this->saveOnTheEntryPage(self::CAR_A, ['VOLTAS' => 7.0, 'LAST' => 3000.0, 'BEST' => 201.0, 'DESCLAS' => 'NÃO', 'APTO' => 'SIM']);
        $this->assertEquals(7, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(201, $this->publishedData(self::CAR_A)['BEST']);

        $this->changeSettings(['modo_publicacao' => 'confirmadas']);
        $this->assertSame(['VOLTAS', 'LAST', 'BEST'], ManualScoreFields::engineOwnedFields($this->prova));
        $this->saveOnTheEntryPage(self::CAR_A, ['VOLTAS' => 99.0, 'DESCLAS' => 'SIM', 'APTO' => 'SIM']);
        $this->assertNotEquals(99, $this->publishedData(self::CAR_A)['VOLTAS'] ?? null, 'a typed lap count is ignored while the engine publishes');
        $this->assertSame('SIM', $this->publishedData(self::CAR_A)['DESCLAS']);
    }

    /**
     * EXPECTED: the settings page offers, as the classification field, the
     * fields of the prova that people type, and never the three the count
     * writes itself.
     */
    public function testTheClassificationFieldIsChosenAmongTheFieldsPeopleType(): void
    {
        $this->assertSame(['DESCLAS', 'APTO'], array_keys(SourceSettings::classificationChoices($this->prova)));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Cars A and B are classified, car C is not; each crosses at about 1:00 and 2:40. */
    private function raceWhereEveryCarCrossesTwice(): void
    {
        $this->setManualField(self::CAR_A, 'APTO', 'SIM');
        $this->setManualField(self::CAR_B, 'APTO', 'SIM');
        $this->startRace(self::RACE_START_MS);
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $index => $car) {
            $this->allCountersSee($car, 60 + $index * 5);
            $this->allCountersSee($car, 160 + $index * 5);
        }
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
    }

    /**
     * What juiz/save_entry.php does with the fields of its form.
     *
     * @param array<string, mixed> $fields
     */
    private function saveOnTheEntryPage(int $car, array $fields): void
    {
        $this->newRequest();
        ManualScoreFields::save($this->prova, $car, $fields);
        $this->newRequest();
    }

    /** The state a reviewer's poll gets right now, without forcing a calculation first. */
    private function screenWithoutRecalculating(): array
    {
        $this->newRequest();
        return \Baja\Contagem\Review\StateSnapshot::live($this->service, $this->service->snapshot(), 'zzt_revisor');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    private function flagsOfType(array $state, string $type): array
    {
        return array_values(array_filter($state['alertas_abertos'], fn(array $flag) => $flag['tipo'] === $type));
    }

    /** Adds an enum SIM/NÃO field to the test prova, as an organiser would in its parameters. */
    private function addInputFieldToTheProva(string $code): void
    {
        $params = json_decode((string) $this->fetchValue(
            'SELECT params FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, self::PROVA_ID]
        ), true);
        $params['inputs'][] = ['code' => $code, 'name' => 'Apto para o enduro', 'type' => 'enum', 'options' => ['SIM', 'NÃO']];
        $this->executeSql(
            'UPDATE prova SET params = ? WHERE evento_id = ? AND prova_id = ?',
            [json_encode($params), self::EVENTO_ID, self::PROVA_ID]
        );
        $this->newRequest();
    }
}
