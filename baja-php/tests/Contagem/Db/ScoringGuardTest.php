<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\SourceSettings;
use Baja\Contagem\Service\ClassificationWriter;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ScoringFields;
use InvalidArgumentException;

/**
 * The classification field and the scoring formula must not share a field.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The Enduro scores with "$DESCLAS=='SIM' ? 0 : (400 * $VOLTAS / $Vmax)":
 * DESCLAS = SIM means DISQUALIFIED, 0 points. Lap counting has its own
 * "classification field", where SIM means the opposite: the car MAY race.
 * "Marcar todos os presentes como aptos" writes SIM into it for every
 * present car.
 *
 * At 26SE the classification field was set to DESCLAS. Every car was marked
 * SIM, so every car scored 0. Lap counting did everything right; nothing on
 * any screen said the two meanings collided.
 *
 * The test prova here has that very formula and the field DESCLAS, plus a
 * field APTO made for the classification (added in setUp). Cars 11, 22
 * and 33 are present.
 */
final class ScoringGuardTest extends ReviewDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addInputFieldToTheProva('APTO');
        Clock::freezeAt($this->at(600));
    }

    /**
     * SITUATION: the controller opens "Fontes e configurações" and picks
     * DESCLAS as the classification field, as happened at 26SE.
     *
     * EXPECTED: the formula is known to read VOLTAS and DESCLAS. DESCLAS is
     * still in the list, marked as used by the formula, so nobody wonders
     * where it went; choosing it is refused with a sentence that says why
     * and what to do instead (a field such as APTO), and nothing is saved.
     * APTO is accepted.
     */
    public function testAFieldTheFormulaReadsIsRefusedAsTheClassificationField(): void
    {
        $this->assertSame(['VOLTAS', 'DESCLAS'], ScoringFields::readByScoring($this->prova));
        $choices = SourceSettings::classificationChoices($this->prova);
        $this->assertSame(['DESCLAS', 'APTO'], array_keys($choices));
        $this->assertStringContainsString('usado na fórmula de pontuação', $choices['DESCLAS']);
        $this->assertStringNotContainsString('fórmula', $choices['APTO']);

        try {
            $this->saveSettingsForm(['campo_classificacao' => 'DESCLAS']);
            $this->fail('DESCLAS was accepted as the classification field');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('DESCLAS', $refusal->getMessage());
            $this->assertStringContainsString('fórmula de pontuação', $refusal->getMessage());
            $this->assertStringContainsString('APTO', $refusal->getMessage());
            $this->assertStringContainsString('Nada foi alterado', $refusal->getMessage());
        }
        $this->newRequest();
        $this->assertNull($this->service->getSettings()->campoClassificacao);

        $this->saveSettingsForm(['campo_classificacao' => 'APTO']);
        $this->newRequest();
        $this->assertSame('APTO', $this->service->getSettings()->campoClassificacao);
        $this->assertNull(ScoringFields::classificationConflict($this->prova));
    }

    /**
     * SITUATION: a prova that was set up before the check existed already
     * has DESCLAS as its classification field, and the race is running.
     * The controller needs to change another setting right now.
     *
     * EXPECTED: the conflict is reported (the Fontes page, the hub and the
     * review screen show it loudly), but the other setting can be saved:
     * refusing every save until the field is sorted out would block race
     * control in the middle of a race. Keeping DESCLAS is allowed; choosing
     * it again after leaving it is not.
     */
    public function testAProvaAlreadyInThatStateIsWarnedAndCanStillChangeItsOtherSettings(): void
    {
        $this->changeSettings(['campo_classificacao' => 'DESCLAS']);
        $this->assertSame('DESCLAS', ScoringFields::classificationConflict($this->prova));
        $this->assertSame(['VOLTAS'], SourceSettings::refusedClassificationFields($this->prova, 'DESCLAS'));
        // Selected and selectable, so its label must not say it cannot be chosen.
        $label = SourceSettings::classificationChoices($this->prova, 'DESCLAS')['DESCLAS'];
        $this->assertStringNotContainsString('não pode ser escolhido', $label);
        $this->assertStringContainsString('troque por um campo próprio (APTO)', $label);

        $this->saveSettingsForm(['janela_ms' => '15000']);

        $this->newRequest();
        $this->assertSame(15000, $this->service->getSettings()->janelaMs);
        $this->assertSame('DESCLAS', $this->service->getSettings()->campoClassificacao);
        $this->assertSame('DESCLAS', $this->freshScreen()['pontuacao']['campo_classificacao_na_formula']);
    }

    /**
     * SITUATION: the same prova, already with DESCLAS as its classification
     * field. On race morning the controller presses "Marcar todos os
     * presentes como aptos" on the hub, as at 26SE.
     *
     * EXPECTED: refused, saying why; no car's DESCLAS was written.
     */
    public function testMarkingEveryCarAsAptIsRefusedWhileTheFieldIsOneTheFormulaReads(): void
    {
        $this->changeSettings(['campo_classificacao' => 'DESCLAS']);

        try {
            $this->service->markAllPresentAsClassified(self::USER_PREFIX . 'controle');
            $this->fail('SIM was written into a field the formula reads');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('DESCLAS', $refusal->getMessage());
            $this->assertStringContainsString('Nada foi alterado', $refusal->getMessage());
        }
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $car) {
            $this->assertNull($this->publishedData($car)['DESCLAS'] ?? null);
        }
    }

    /**
     * SITUATION: 26SE replayed. DESCLAS is the classification field. Before
     * race day a judge disqualified car 33 on the entry page (DESCLAS = SIM,
     * as the formula means it); on race morning the hub marked the other
     * present cars SIM; car 11 does two laps.
     *
     * EXPECTED: the review screen's state says every published points value
     * is 0 although a car has laps, names the field, and says when points
     * were recalculated.
     *
     * THEN the fix, in the order docs/contagem.md gives, which never takes a
     * car out of the race: add APTO, copy DESCLAS into it, switch the
     * classification field to APTO, empty DESCLAS only where the hub wrote
     * it (found through the hub's own lines in the log table), "Republicar
     * tudo". After each step the same three cars race and car 11 keeps its
     * laps; at the end car 11 has its 400 points, car 33 is still
     * disqualified, and the warnings are gone.
     */
    public function testTheReviewScreenSaysWhenEveryCarScoresZeroAndTheFixKeepsEveryCarInTheRace(): void
    {
        $this->changeSettings(['campo_classificacao' => 'DESCLAS']);
        // The judge's disqualification, logged as the entry page does.
        $this->setManualField(self::CAR_C, 'DESCLAS', 'SIM');
        $this->writeEntryPageLogLine(self::CAR_C, ['DESCLAS' => 'SIM']);
        // What the button did at 26SE (the service refuses now; see the
        // test above): SIM, with its log line, on every blank field.
        $this->newRequest();
        $marked = ClassificationWriter::markAllPresent($this->prova, self::USER_PREFIX . 'controle');
        $this->assertSame([self::CAR_A, self::CAR_B], $marked['marcados']);

        $this->startRace(self::RACE_START_MS);
        foreach ([60, 240, 420] as $seconds) {
            $this->recordCountedPass(self::CAR_A, $this->at($seconds));
        }
        $this->recompute();
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(0, $this->publishedPoints(self::CAR_A)['ENDPTS'], 'the 26SE outcome');

        $check = $this->freshScreen()['pontuacao'];
        $this->assertTrue($check['todos_os_pontos_zero']);
        $this->assertSame('DESCLAS', $check['campo_classificacao_na_formula']);
        $this->assertIsInt($check['recalculados_em_ms']);

        $racing = [self::CAR_A, self::CAR_B, self::CAR_C];
        $this->assertCarsRacing($racing);

        // 1 and 2: APTO exists (setUp); copy DESCLAS into it, NÃO included.
        $this->executeSql(
            "UPDATE input SET dados = JSON_SET(dados, '$.APTO', JSON_EXTRACT(dados, '$.DESCLAS'))
              WHERE evento_id = ? AND prova_id = ? AND JSON_EXTRACT(dados, '$.DESCLAS') IS NOT NULL",
            [self::EVENTO_ID, self::PROVA_ID]
        );
        $this->assertCarsRacing($racing);

        // 3: the classification field becomes APTO, on the Fontes page.
        $this->saveSettingsForm(['campo_classificacao' => 'APTO']);
        $this->assertCarsRacing($racing);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);

        // 4: DESCLAS goes back to meaning "disqualified", emptied only where
        // the hub wrote it; the SQL is the one in docs/contagem.md.
        $this->clearTheHubsDesclas();
        $this->assertCarsRacing($racing);
        $this->assertNull($this->publishedData(self::CAR_A)['DESCLAS']);
        $this->assertNull($this->publishedData(self::CAR_B)['DESCLAS']);
        $this->assertSame('SIM', $this->publishedData(self::CAR_C)['DESCLAS'], "the judge's disqualification survives");

        // 5: "Republicar tudo" recalculates the points.
        $this->newRequest();
        $this->service->republishAll();

        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(400, $this->publishedPoints(self::CAR_A)['ENDPTS']);
        $check = $this->freshScreen()['pontuacao'];
        $this->assertFalse($check['todos_os_pontos_zero']);
        $this->assertNull($check['campo_classificacao_na_formula']);
    }

    /**
     * SITUATION: the same repair, but after the hub marked car 22 a judge
     * saved its DESCLAS on the entry page (a later log line for that car).
     *
     * EXPECTED: step 4 leaves car 22 alone. Whether the judge meant a
     * disqualification is for people to settle, not for the SQL.
     */
    public function testTheRepairLeavesAloneACarWhoseDesclasAJudgeSavedAfterTheHub(): void
    {
        $this->changeSettings(['campo_classificacao' => 'DESCLAS']);
        $this->newRequest();
        ClassificationWriter::markAllPresent($this->prova, self::USER_PREFIX . 'controle');
        $this->writeEntryPageLogLine(self::CAR_B, ['DESCLAS' => 'SIM']);

        $this->clearTheHubsDesclas();

        $this->assertNull($this->publishedData(self::CAR_A)['DESCLAS']);
        $this->assertSame('SIM', $this->publishedData(self::CAR_B)['DESCLAS']);
        $this->assertNull($this->publishedData(self::CAR_C)['DESCLAS']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param int[] $cars */
    private function assertCarsRacing(array $cars): void
    {
        $this->newRequest();
        $this->assertSame($cars, array_keys($this->service->carRoster()->classified()));
    }

    /**
     * Submits the settings form: every field as the page would send it for
     * the settings in force, with the given ones changed.
     *
     * @param array<string, string> $changes
     */
    private function saveSettingsForm(array $changes): void
    {
        $this->newRequest();
        $form = [];
        foreach ($this->service->getSettings()->toArray() as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $form[$key] = '1';
                }
                continue;
            }
            $form[$key] = $value === null ? '' : str_replace('.', ',', (string) $value);
        }
        SourceSettings::save($this->service, $changes + $form);
    }

    /**
     * Step 4 of the repair in docs/contagem.md ("Classified cars"), word for
     * word but for the placeholders: empties DESCLAS = SIM only for the cars
     * the hub's button wrote it for, and that no later log line touched.
     * IF(JSON_VALID(...)) because old log lines are not always JSON.
     */
    private function clearTheHubsDesclas(): void
    {
        $this->executeSql(
            "UPDATE input SET dados = JSON_SET(dados, '$.DESCLAS', NULL)
              WHERE evento_id = ? AND prova_id = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.DESCLAS')) = 'SIM'
                AND equipe_id IN (
                  SELECT h.equipe FROM log h
                   WHERE h.pagina = ?
                     AND IF(JSON_VALID(h.dados), JSON_UNQUOTE(JSON_EXTRACT(h.dados, '$.origem')), NULL) = 'marcar todos os presentes'
                     AND NOT EXISTS (
                       SELECT 1 FROM log m
                        WHERE m.pagina = h.pagina AND m.equipe = h.equipe AND m.id > h.id
                          AND IF(JSON_VALID(m.dados), JSON_CONTAINS_PATH(m.dados, 'one', '$.DESCLAS', '$.DELETED'), 0)))",
            [self::EVENTO_ID, self::PROVA_ID, self::EVENTO_ID . '_' . self::PROVA_ID]
        );
    }

    /**
     * The line juiz/save_entry.php writes when a judge saves a car's fields:
     * the changed fields, without "origem".
     *
     * @param array<string, mixed> $diff
     */
    private function writeEntryPageLogLine(int $car, array $diff): void
    {
        $this->executeSql(
            'INSERT INTO `log` (`user`, pagina, equipe, dados) VALUES (?, ?, ?, ?)',
            [self::USER_PREFIX . 'juiz', self::EVENTO_ID . '_' . self::PROVA_ID, $car, json_encode($diff)]
        );
    }

    /** Adds an enum SIM/NÃO field to the test prova, as an organiser would in its parameters. */
    private function addInputFieldToTheProva(string $code): void
    {
        $this->executeSql(
            "UPDATE prova SET params = JSON_ARRAY_APPEND(params, '$.inputs', CAST(? AS JSON)) WHERE evento_id = ? AND prova_id = ?",
            [json_encode(['code' => $code, 'name' => 'Apto para o enduro', 'type' => 'enum', 'options' => ['SIM', 'NÃO']]), self::EVENTO_ID, self::PROVA_ID]
        );
        $this->newRequest();
    }
}
