<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;

/**
 * "Editar manualmente" on the review screen, as the server sees it
 * (round 4f): moving the stray entry of a "troca provável", and getting out
 * of a flag that a hand-added pass caused.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * When one counter types a wrong number, the system has a LONE entry for
 * one car at the moment the other two counters recorded another car, and
 * raises the flag "troca provável". What settles it is the decision
 * "reatribuir": this entry was really that car. The manual form of the
 * screen sends that decision, the same one "Aceitar sugestão" sends.
 *
 * The race: start at 0:00. Cars A, B and C lap every two minutes (A first
 * at 1:00, B at 1:10, C at 2:00), typed by all three counters. On B's
 * fourth crossing, at 7:10, counter 3 types C's number instead of B's; C
 * itself is half a lap away at that moment.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewManualEditTest extends ReviewDbTestCase
{
    /** The entry counter 3 typed with the wrong number. */
    private int $strayEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startRace(self::RACE_START_MS);
        foreach ([0, 1, 2, 3, 4] as $lap) {
            $this->allCountersSee(self::CAR_A, 60 + $lap * 120);
            $this->allCountersSee(self::CAR_C, 120 + $lap * 120);
            if ($lap !== 3) {
                $this->allCountersSee(self::CAR_B, 70 + $lap * 120);
                continue;
            }
            $this->addEntry($this->fontes[0], self::CAR_B, $this->at(430));
            $this->addEntry($this->fontes[1], self::CAR_B, $this->at(431));
            $this->strayEntry = $this->addEntry($this->fontes[2], self::CAR_C, $this->at(432));
        }
        $this->syncAll($this->at(700));
        Clock::freezeAt($this->at(720));
    }

    /**
     * SITUATION: as above. The reviewer opens the "troca provável" flag,
     * presses "Editar manualmente" and uses the form "Mover o registro do
     * contador Contagem 3 para o carro B".
     *
     * WHAT WENT WRONG: the manual form was "Adicionar passagem" with car C
     * (the wrongly typed number) filled in. Pressing it gave car C a lap it
     * never drove and left the stray entry as it was; the reviewer saw
     * "still pending".
     *
     * EXPECTED: the form sends "reatribuir" for the stray entry. The flag
     * is gone, no other flag opened in its place, the laps of both cars
     * are what they were (B: 4, C: 4; the pass counted already by the two
     * counters who typed it right), counter 3's entry is now part of B's
     * pass, and the decision is in the history and can be undone.
     */
    public function testMovingTheStrayEntryByHandClosesTheFlagAndLeavesTheCountRight(): void
    {
        $before = $this->freshScreen();
        $flag = $this->swapFlag($before);
        $this->assertSame($this->strayEntry, $flag['passagem_ids'][0], 'the flag\'s first entry is the stray one');
        $this->assertSame(self::CAR_C, $flag['carros'][0], 'and its first car is the number typed WRONG');
        $this->assertSame((int) $this->fontes[2]->getFonteId(), $flag['fonte_id']);
        $lapsBefore = [$this->carRow($before, self::CAR_B)['voltas'], $this->carRow($before, self::CAR_C)['voltas']];

        $answer = $this->decide(
            ['tipo' => 'reatribuir', 'passagem_id' => $this->strayEntry, 'equipe_numero' => self::CAR_B],
            $flag['passagem_ids'],
            $flag['assinatura']
        );

        $this->assertSame(200, $answer['status']);
        $after = $answer['body']['estado'];
        $this->assertSame([], $after['alertas_abertos'], 'the flag is gone and nothing opened in its place');
        $this->assertSame($lapsBefore, [$this->carRow($after, self::CAR_B)['voltas'], $this->carRow($after, self::CAR_C)['voltas']]);
        $this->assertSame([4, 4], $lapsBefore);
        $this->assertSame(5, $this->carRow($after, self::CAR_B)['contagem_por_fonte'][$this->fontes[2]->getFonteId()], 'counter 3 now has all five of B\'s passes');
        $this->assertSame('reatribuir', $after['historico'][0]['tipo']);
        $this->assertTrue($after['historico'][0]['pode_desfazer']);
    }

    /**
     * SITUATION: the same flag. The reviewer leaves the car of the form as
     * the one the entry already has (C) and presses "Mover".
     *
     * WHAT WENT WRONG: the server stored it. A "correction" that corrects
     * nothing sat in the history, and the flag stayed open.
     *
     * EXPECTED: refused with a sentence, nothing stored.
     */
    public function testMovingAnEntryToTheCarItAlreadyHasIsRefused(): void
    {
        $flag = $this->swapFlag($this->freshScreen());

        $answer = $this->decide(
            ['tipo' => 'reatribuir', 'passagem_id' => $this->strayEntry, 'equipe_numero' => self::CAR_C],
            $flag['passagem_ids'],
            $flag['assinatura']
        );

        $this->assertSame(400, $answer['status']);
        $this->assertStringContainsString('já é do carro ' . self::CAR_C, $answer['body']['mensagem']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: a reviewer adds a pass for car A by hand at 7:30, where no
     * counter has an entry, 29 s after A's pass at 7:00. What a reviewer
     * says counts, so car A gains a lap, and the engine flags the two
     * passes as a lap too short to be real ("volta curta"). The added pass
     * is one of the flag's entries; it is no row of the entry table, and
     * carries an id below zero.
     *
     * EXPECTED: the reviewer can get out of it FROM THAT FLAG. "Descartar"
     * on the added pass, sent with the flag's own entries and signature,
     * removes the addition: car A is back to its laps and the flag is
     * gone. (K on the flag is accepted too; it keeps both passes and only
     * stops the warning.)
     */
    public function testAFlagCausedByAHandAddedPassCanBeSettledFromTheFlag(): void
    {
        $lapsBefore = $this->carRow($this->freshScreen(), self::CAR_A)['voltas'];
        $added = $this->decide(['tipo' => 'adicionar', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(450)]);
        $this->assertSame(200, $added['status']);
        $addedPassId = -$added['body']['decisao_id'];
        $state = $added['body']['estado'];
        $this->assertSame($lapsBefore + 1, $this->carRow($state, self::CAR_A)['voltas']);
        $flag = null;
        foreach ($state['alertas_abertos'] as $open) {
            if (in_array($addedPassId, $open['passagem_ids'], true)) {
                $flag = $open;
            }
        }
        $this->assertNotNull($flag, 'the added pass is flagged');
        $this->assertSame('volta_curta', $flag['tipo']);

        // K: accepted on a flag whose first entry is the added pass.
        $kept = $this->decide(['tipo' => 'confirmar', 'passagem_id' => $flag['passagem_ids'][0]], $flag['passagem_ids'], $flag['assinatura']);
        $this->assertSame(200, $kept['status']);
        $undoK = $this->decide(['tipo' => 'desfazer', 'desfaz_decisao_id' => $kept['body']['decisao_id']]);
        $this->assertSame(200, $undoK['status']);
        $flagAgain = null;
        foreach ($undoK['body']['estado']['alertas_abertos'] as $open) {
            if (in_array($addedPassId, $open['passagem_ids'], true)) {
                $flagAgain = $open;
            }
        }
        $this->assertNotNull($flagAgain);

        // "Descartar" the added pass from the flag.
        $discarded = $this->decide(['tipo' => 'descartar_passagem', 'passagem_id' => $addedPassId], $flagAgain['passagem_ids'], $flagAgain['assinatura']);

        $this->assertSame(200, $discarded['status']);
        $this->assertSame($lapsBefore, $this->carRow($discarded['body']['estado'], self::CAR_A)['voltas']);
        foreach ($discarded['body']['estado']['alertas_abertos'] as $open) {
            $this->assertNotContains($addedPassId, $open['passagem_ids']);
        }
        $this->assertSame([], $discarded['body']['estado']['historico_em_vigor'], 'the addition no longer stands');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function swapFlag(array $state): array
    {
        foreach ($state['alertas_abertos'] as $flag) {
            if ($flag['tipo'] === 'troca_provavel') {
                return $flag;
            }
        }
        $this->fail('no "troca provável" flag is open: ' . json_encode(array_column($state['alertas_abertos'], 'tipo')));
    }
}
