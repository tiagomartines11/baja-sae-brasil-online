<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;

/**
 * Which cars take part in the race: present AND classified.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Not every team that shows up at the event may start the endurance race;
 * a car has to pass the earlier inspections first. A prova can name one of
 * its own score fields as its "classification field" (the setting
 * `campo_classificacao`; in these tests the field is called APTO). A car is
 * classified when that field holds "SIM". Anything else, including an empty
 * field, means NOT classified: a car nobody has looked at must not slip
 * into a race.
 *
 * With no classification field configured, every present car takes part,
 * which is how things worked before this setting existed.
 *
 * The three made-up cars are 11, 22 and 33. In most tests here 11 and 22
 * are classified and 33 is not.
 */
final class ClassifiedCarsTest extends DbTestCase
{
    private const FIELD = 'APTO';

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    private function useClassificationField(): void
    {
        $this->setManualField(self::CAR_A, self::FIELD, 'SIM');
        $this->setManualField(self::CAR_B, self::FIELD, 'SIM');
        $this->changeSettings(['campo_classificacao' => self::FIELD]);
    }

    /** @param array<int, array{numero: int}> $cars */
    private static function numbersOf(array $cars): array
    {
        return array_values(array_map(fn(array $car): int => $car['numero'], $cars));
    }

    /**
     * SITUATION: the prova has no classification field (the default).
     *
     * EXPECTED: all three present cars take part; none is "unclassified".
     */
    public function testWithoutAClassificationFieldEveryPresentCarTakesPart(): void
    {
        $roster = $this->service->carRoster();

        $this->assertNull($roster->classificationField());
        $this->assertSame([self::CAR_A, self::CAR_B, self::CAR_C], array_keys($roster->classified()));
        $this->assertSame([], $roster->unclassified());
    }

    /**
     * SITUATION: the prova uses the field APTO. Car 11 has "SIM", car 22
     * has "sim" typed in lower case with a stray space, car 33 has nothing.
     *
     * EXPECTED: 11 and 22 are classified (the comparison forgives case and
     * spaces, because the value is typed by people); 33 is not: blank means
     * not classified.
     */
    public function testOnlyCarsWhoseFieldSaysSimAreClassified(): void
    {
        $this->useClassificationField();
        $this->setManualField(self::CAR_B, self::FIELD, ' sim ');
        $this->newRequest();

        $roster = $this->service->carRoster();

        $this->assertSame([self::CAR_A, self::CAR_B], array_keys($roster->classified()));
        $this->assertSame([self::CAR_C], array_keys($roster->unclassified()));
    }

    /**
     * SITUATION: a count page asks the server which cars it should show.
     *
     * EXPECTED: the answer lists the classified cars as `valid_cars` (these
     * become the tiles) and the unclassified ones separately, so the page
     * can warn "carro não classificado" instead of the vaguer "número
     * desconhecido" when a counter types one.
     */
    public function testThePhoneIsToldWhichCarsAreClassifiedAndWhichAreNot(): void
    {
        $this->useClassificationField();
        $token = $this->issueToken($this->fontes[0]);

        $answer = $this->upload($token)['body'];

        $this->assertSame([self::CAR_A, self::CAR_B], self::numbersOf($answer['valid_cars']));
        $this->assertSame([self::CAR_C], self::numbersOf($answer['unclassified_cars']));
    }

    /**
     * SITUATION: both counters type car 33, which is not classified, twice
     * (it is on the track anyway, or two people made the same mistake).
     *
     * EXPECTED: the entries ARE stored: the phone's list of cars may be out
     * of date, and keeping everything is what lets a protest be answered.
     * But the passes do not count: no lap is published for car 33.
     */
    public function testPassesOfAnUnclassifiedCarAreStoredButDoNotCount(): void
    {
        $this->useClassificationField();
        $token1 = $this->issueToken($this->fontes[0]);
        $token2 = $this->issueToken($this->fontes[1]);

        foreach ([60, 240] as $seconds) {
            $this->upload($token1, [$this->entryPayload(self::newUuid(), self::CAR_C, $this->at($seconds))]);
            $answer = $this->upload($token2, [$this->entryPayload(self::newUuid(), self::CAR_C, $this->at($seconds))]);
            $this->assertCount(1, $answer['body']['stored_uuids']);
        }

        $this->assertSame(4, $this->countRows(
            'contagem_passagem',
            'equipe_numero = ? AND fonte_id IN (?, ?)',
            [self::CAR_C, $this->fontes[0]->getFonteId(), $this->fontes[1]->getFonteId()]
        ));
        $this->assertNull($this->publishedData(self::CAR_C)['VOLTAS'] ?? null, 'no lap count was published for car 33');
    }

    /**
     * SITUATION: the same car 33 with its two uncounted passes. The judges
     * then decide it may race after all, and the person in charge presses
     * "Marcar todos os presentes como aptos" on the hub.
     *
     * EXPECTED: one car was changed (33; the other two already had "SIM").
     * Its field now says SIM, a line in the application's log records who
     * did it, and the race is recounted at once: the passes it already had
     * now give it one lap.
     */
    public function testMarkingEveryPresentCarAsClassifiedRecountsTheRace(): void
    {
        $this->useClassificationField();
        foreach ([60, 240] as $seconds) {
            $this->recordCountedPass(self::CAR_C, $this->at($seconds));
        }
        $this->recompute();
        $this->assertNull($this->publishedData(self::CAR_C)['VOLTAS'] ?? null);

        $this->newRequest();
        $outcome = $this->service->markAllPresentAsClassified(self::USER_PREFIX . 'controle');

        $this->assertSame(['marcados' => [self::CAR_C], 'ja_nao' => []], $outcome);
        $this->assertSame('SIM', $this->publishedData(self::CAR_C)[self::FIELD]);
        $this->assertEquals(1, $this->publishedData(self::CAR_C)['VOLTAS']);
        $this->assertSame(1, $this->countRows(
            'log',
            'user = ? AND pagina = ? AND equipe = ?',
            [self::USER_PREFIX . 'controle', self::EVENTO_ID . '_' . self::PROVA_ID, self::CAR_C]
        ));
    }

    /**
     * SITUATION: the button is pressed on a prova that has no
     * classification field.
     *
     * EXPECTED: nothing happens and it says so (0 cars changed). There is
     * no field to fill in.
     */
    public function testTheButtonDoesNothingWithoutAClassificationField(): void
    {
        $outcome = $this->service->markAllPresentAsClassified(self::USER_PREFIX . 'controle');

        $this->assertSame(['marcados' => [], 'ja_nao' => []], $outcome);
        $this->assertNull($this->publishedData(self::CAR_C));
    }

    /**
     * SITUATION: a judge looked at car 22 and wrote "NÃO" in its
     * classification field: it failed an inspection and may not race. Car
     * 33's field is still blank. Then somebody presses "Marcar todos os
     * presentes como aptos".
     *
     * WHAT WENT WRONG BEFORE: the button wrote "SIM" over everything that
     * was not "SIM", and the judge's decision about car 22 was gone, with
     * one click meant to save typing.
     *
     * EXPECTED: only the blank field is filled in (car 33). Car 22 keeps
     * its "NÃO" and is reported back separately, so the page can say "these
     * were left alone".
     */
    public function testMarkingEveryCarOnlyFillsInBlankFields(): void
    {
        $this->useClassificationField();
        $this->setManualField(self::CAR_B, self::FIELD, 'NÃO');
        $this->newRequest();

        $outcome = $this->service->markAllPresentAsClassified(self::USER_PREFIX . 'controle');

        $this->assertSame(['marcados' => [self::CAR_C], 'ja_nao' => [self::CAR_B]], $outcome);
        $this->assertSame('NÃO', $this->publishedData(self::CAR_B)[self::FIELD]);
        $this->assertSame('SIM', $this->publishedData(self::CAR_C)[self::FIELD]);
    }
}
