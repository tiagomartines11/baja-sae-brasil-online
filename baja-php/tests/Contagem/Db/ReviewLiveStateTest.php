<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemFonte;

/**
 * The review screen's state, round 4: what must NOT change the snapshot's
 * version, and the new things the state says.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The server keeps the result of the last full calculation (the "snapshot")
 * and gives it a version: a digest of its content. A review screen sends
 * the version it has with every poll, and is told "inalterado" (unchanged)
 * when it is still the current one, instead of downloading everything.
 *
 * Every phone tells the server every few seconds "I am here, and I have
 * uploaded everything up to this moment". Those two numbers per phone were
 * part of the content, so the version changed with every heartbeat and
 * every screen downloaded the whole state again, for nothing.
 * StateSnapshot::LIVE_KEYS names the parts that are left out of the digest;
 * they are sent fresh with every answer instead.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewLiveStateTest extends ReviewDbTestCase
{
    /**
     * SITUATION: car A has crossed twice and everything is calculated at
     * 5:00. In the next ten seconds nothing is typed; the three phones just
     * send their heartbeat: "seen at 5:10, synced through 5:10". The race
     * is recalculated.
     *
     * WHAT WENT WRONG: the new snapshot had another version, although no
     * flag, no lap and no car had changed. Every review screen fetched the
     * whole state again every few seconds.
     *
     * EXPECTED: the version is the same. The numbers that did change are
     * nowhere in the stored state except under the keys named by
     * LIVE_KEYS, and the screen still gets them: the answer to an
     * up-to-date screen ("inalterado") carries each source's new "synced
     * through" mark.
     */
    public function testAHeartbeatDoesNotChangeTheVersionButItsNumbersStillReachTheScreen(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->allCountersSee(self::CAR_A, 260);
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $before = $this->service->snapshot();

        $this->syncAll($this->at(310));
        Clock::freezeAt($this->at(311));
        $this->recompute();
        $after = $this->service->snapshot();

        $this->assertSame($before->token, $after->token, 'a heartbeat alone must not make a new version');

        // Outside the live keys, the two stored states are the same to the last byte.
        $withoutLiveKeys = fn(array $state): array => array_diff_key($state, array_flip(StateSnapshot::LIVE_KEYS));
        $this->assertSame(
            json_encode($withoutLiveKeys($before->dados['estado'])),
            json_encode($withoutLiveKeys($after->dados['estado'])),
            'a number that moves with the heartbeats is stored outside StateSnapshot::LIVE_KEYS'
        );
        foreach ($after->dados['estado']['fontes'] as $source) {
            foreach (['visto_em_ms', 'sincronizado_ate_ms', 'offline'] as $contactField) {
                $this->assertArrayNotHasKey($contactField, $source['stats'], 'contact is kept under "contato_das_fontes"');
            }
        }

        $unchanged = StateSnapshot::unchanged($this->service, $after, 'zzt_ana');
        $full = StateSnapshot::live($this->service, $after, 'zzt_ana');
        foreach ($this->fontes as $fonte) {
            $fonteId = (int) $fonte->getFonteId();
            $this->assertSame($this->at(310), ((array) $unchanged['contato_das_fontes'])[$fonteId]['sincronizado_ate_ms']);
            // The full state still has it where the screen reads it: in the source's statistics.
            $stats = $this->sourceStats($full, $fonte);
            $this->assertSame($this->at(310), $stats['sincronizado_ate_ms']);
            $this->assertSame($this->at(310), $stats['visto_em_ms']);
            $this->assertFalse($stats['nada_a_esperar']);
            $this->assertArrayHasKey('entradas', $stats, 'and the count statistics beside it');
        }
        $this->assertArrayNotHasKey('contato_das_fontes', $full, 'the full state has no second copy of it');
    }

    /**
     * SITUATION: the same race; the phones of Contagem 1 go quiet. Nothing
     * is recalculated, the screen keeps polling.
     *
     * EXPECTED: with no open device left, the source says "nothing to wait
     * for" in the live answer, worked out at that moment by the same rule
     * the engine uses.
     */
    public function testTheLiveSyncMarkFollowsTheDevicesWithoutARecalculation(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $snapshot = $this->service->snapshot();

        // Eleven minutes of silence: past `dispositivo_ausente_s` (600 s).
        Clock::freezeAt($this->at(290 + 660));
        $stats = $this->sourceStats(StateSnapshot::live($this->service, $snapshot, null), $this->fontes[0]);

        $this->assertTrue($stats['nada_a_esperar']);
        $this->assertNull($stats['sincronizado_ate_ms']);
    }

    /**
     * SITUATION: the race is calculated at 5:00 and then nobody types for
     * a while. Later an entry is uploaded and not yet calculated.
     *
     * WHAT WENT WRONG: "calculado há 27 s" turned red 15 s after every
     * calculation of a quiet race: the engine recalculates only when
     * something changed, so an old calculation alone says nothing.
     *
     * EXPECTED: the state says whether changes are waiting for the next
     * calculation, also in the small "inalterado" answer.
     */
    public function testTheStateSaysWhetherChangesAreWaitingForACalculation(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $this->newRequest();
        $snapshot = $this->service->snapshot();

        Clock::freezeAt($this->at(400));
        $this->assertFalse(StateSnapshot::unchanged($this->service, $snapshot, null)['mudancas_pendentes']);

        $this->addEntry($this->fontes[0], self::CAR_B, $this->at(390));
        $this->service->markChanged();
        $this->newRequest();
        $this->assertTrue(StateSnapshot::unchanged($this->service, $snapshot, null)['mudancas_pendentes']);
    }

    /**
     * SITUATION: an ordinary race. The state is asked for.
     *
     * EXPECTED: it carries the two things the screen turns into banners:
     * whether the test preset is applied, and the corrections the
     * recalculation had to make to published counts (none here). Both are
     * also in the small "inalterado" answer, because neither changes a
     * flag or a lap: without that an open screen would never show them.
     */
    public function testTheStateCarriesThePresetBannerAndTheReconcileCorrections(): void
    {
        $this->startRace(self::RACE_START_MS);
        $state = $this->freshScreen();
        $this->assertFalse($state['predefinicao_de_teste']);
        $this->assertSame([], $state['correcoes_da_reconciliacao']);

        $this->newRequest();
        $this->service->applyTestPreset(60);
        $this->newRequest();
        $unchanged = StateSnapshot::unchanged($this->service, $this->service->snapshot(), 'zzt_ana');

        $this->assertTrue($unchanged['predefinicao_de_teste']);
        $this->assertSame([], $unchanged['correcoes_da_reconciliacao']);
    }

    /**
     * SITUATION: on the morning of the race nobody has marked the teams as
     * present at the event yet (`equipe.presente` is 0 for all). A counter
     * tries the page and types car A, a real team.
     *
     * WHAT WENT WRONG: the review table labelled the car "número
     * desconhecido", as it would a typo. Every car of the race got that.
     *
     * EXPECTED: the state says once that no team is marked present, and
     * the car's row says the number IS a team of the event, just not
     * present. A number no team has (99) is still told apart.
     */
    public function testWithNoTeamMarkedPresentTheCarsAreNotCalledUnknownNumbers(): void
    {
        $this->executeSql('UPDATE equipe SET presente = 0 WHERE evento_id = ?', [self::EVENTO_ID]);
        $this->startRace(self::RACE_START_MS);
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(99, $this->at(70));
        $this->syncAll($this->at(100));

        $state = $this->freshScreen();

        $this->assertTrue($state['sem_equipes_presentes']);
        $carA = $this->carRow($state, self::CAR_A);
        $this->assertFalse($carA['presente']);
        $this->assertTrue($carA['inscrito'], 'car A is a team of the event');
        $this->assertFalse($this->carRow($state, 99)['inscrito'], 'no team has number 99');
    }

    public function testWithTeamsPresentTheStateDoesNotSayNobodyIs(): void
    {
        $this->startRace(self::RACE_START_MS);

        $this->assertFalse($this->freshScreen()['sem_equipes_presentes']);
    }

    /**
     * SITUATION: the phone at the pit lane entrance had no usable clock
     * when it logged car A going in (its time is "desconhecido").
     *
     * WHAT WENT WRONG: the event had no time quality at all. It was paired
     * as if its time were good, and could open a stay that began at
     * whatever the phone's clock said.
     *
     * EXPECTED: the engine pairs nothing with it. The review screen lists
     * it apart, flagged, in the state (for the whole race) and under the
     * lanes of that car; it is not drawn on the pit lane, where its place
     * would mean nothing.
     */
    public function testAPitEventOfUnknownTimeIsListedApartAndNotOnTheLane(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->newRequest();
        $this->service->withLock(function (): void {
            $this->service->recordOcorrencia(Ocorrencia::BOX_ENTRADA, $this->at(120), self::CAR_A, 'zzt_box');
        });
        $this->executeSql(
            'UPDATE contagem_ocorrencia SET qualidade_tempo = ? WHERE evento_id = ? AND prova_id = ? AND tipo = ?',
            ['desconhecido', self::EVENTO_ID, self::PROVA_ID, Ocorrencia::BOX_ENTRADA]
        );
        Clock::freezeAt($this->at(300));

        $state = $this->freshScreen();
        $this->assertCount(1, $state['paradas_sem_horario']);
        $this->assertSame(['box', Ocorrencia::BOX_ENTRADA, self::CAR_A], [
            $state['paradas_sem_horario'][0]['area'], $state['paradas_sem_horario'][0]['tipo'], $state['paradas_sem_horario'][0]['carro'],
        ]);

        $this->newRequest();
        $timeline = TimelineWindow::forCar($this->service, self::CAR_A);
        $this->assertCount(1, $timeline['paradas_sem_horario']);
        $this->assertSame([], $timeline['estadias'], 'it opened no stay');
        foreach ($timeline['ocorrencias'] as $mark) {
            $this->assertNotSame(Ocorrencia::BOX_ENTRADA, $mark['tipo'], 'it is not placed on the pit lane');
        }
        $this->newRequest();
        $this->assertSame([], TimelineWindow::forCar($this->service, self::CAR_B)['paradas_sem_horario'], 'another car\'s timeline does not list it');
    }

    /**
     * SITUATION: the race was finished at 10:00. Car A crosses once more
     * (counts: the pass after the flag) and then a second time on its way
     * in, which the rules leave out.
     *
     * WHAT WENT WRONG: the box of an entry of that second pass offered
     * "Contar esta passagem (+1 volta)". Confirming it changes nothing.
     *
     * EXPECTED: the timeline says per pass whether confirming would add
     * one ("soma_ao_confirmar"). False for the pass the rules leave out,
     * and false for a pass that already counts.
     */
    public function testTheTimelineSaysWhetherConfirmingAPassWouldAddOne(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->newRequest();
        $this->service->withLock(function (): void {
            $this->service->recordOcorrencia(Ocorrencia::ENCERRAMENTO, $this->at(600), null, 'zzt_controle');
        });
        $counted = $this->allCountersSee(self::CAR_A, 660);
        $leftOut = $this->allCountersSee(self::CAR_A, 900);
        $lone = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(300));
        $this->syncAll($this->at(1000));
        Clock::freezeAt($this->at(1200));

        $this->newRequest();
        $groupOf = [];
        foreach ([self::CAR_A, self::CAR_B] as $car) {
            foreach (TimelineWindow::forCar($this->service, $car)['raias'] as $lane) {
                foreach ($lane['entradas'] as $entry) {
                    $groupOf[$entry['id']] = $entry['grupo'];
                }
            }
        }

        $this->assertSame('apos_encerramento', $groupOf[$leftOut[0]]['papel']);
        $this->assertFalse($groupOf[$leftOut[0]]['soma_ao_confirmar'], 'the rules leave it out whoever confirms');
        $this->assertFalse($groupOf[$counted[0]]['soma_ao_confirmar'], 'it counts already');
        $this->assertTrue($groupOf[$lone]['soma_ao_confirmar'], 'one counter only: confirming makes it count');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function sourceStats(array $state, ContagemFonte $fonte): array
    {
        foreach ($state['fontes'] as $row) {
            if ($row['id'] === (int) $fonte->getFonteId()) {
                return $row['stats'];
            }
        }
        $this->fail('source ' . $fonte->getNome() . ' is not in the state');
    }
}
