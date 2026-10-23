<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemFonte;
use Propel\Runtime\Propel;

/**
 * The "state" the review screen draws, and the two halves it is made of.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Working out flags and lap times needs every entry of the whole race, and
 * takes about a tenth of a second. Several reviewers ask for the state
 * every two seconds, so the server does that work at most once every few
 * seconds and keeps the result: the "snapshot".
 *
 * But some things on the screen change although nobody typed anything: the
 * race clock, a car that has not passed for too long, a phone that went
 * quiet. Those cannot be stored. So the state is made in two steps:
 *
 *   StateSnapshot::fromResult()  the part that is stored in the snapshot
 *   StateSnapshot::live()        that part plus what depends on the moment
 *                                and on who is asking
 *
 * These tests check that each thing is in the right half.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewStateTest extends ReviewDbTestCase
{
    /**
     * SITUATION: car A crosses the line three times and all three counters
     * record each crossing. The race is recalculated five minutes in.
     *
     * EXPECTED: the stored half has the car table, the sources, the flags
     * and the history, and NOTHING that depends on the clock or the viewer:
     * no version, no "now", no race clock, no list of reviewers, no status
     * on the cars, no "who is looking" on the flags. If any of those were
     * stored, every reviewer would be shown the clock of whoever happened
     * to trigger the calculation.
     */
    public function testTheStoredHalfHasNothingThatDependsOnTheClockOrTheViewer(): void
    {
        $this->raceWithThreeCrossingsOfCarA();
        Clock::freezeAt($this->at(300));
        $this->recompute();

        $stored = $this->service->snapshot()->dados['estado'];

        foreach (['fontes', 'dispositivos', 'alertas_abertos', 'alertas_resolvidos', 'carros', 'carros_validos', 'configuracoes', 'historico', 'historico_em_vigor', 'ultima_decisao_id'] as $key) {
            $this->assertArrayHasKey($key, $stored);
        }
        foreach (['versao', 'servidor_agora_ms', 'corrida', 'revisores', 'publicado_em_ms', 'olhando'] as $key) {
            $this->assertArrayNotHasKey($key, $stored, '"' . $key . '" changes with time or viewer and must not be stored');
        }
        $this->assertArrayNotHasKey('status', $this->carRow($stored, self::CAR_A));
    }

    /**
     * SITUATION: the same race. Three crossings of car A.
     *
     * EXPECTED: the car table shows 2 laps, not 3. The counting line is
     * after the start line, so the first crossing ("pass zero") completes
     * no lap. The row also says how many official passes that is (3), which
     * is what each counter's own number (3 entries each) is compared with.
     */
    public function testTheCarTableShowsOfficialLapsAndThePassZeroIsNotOne(): void
    {
        $this->raceWithThreeCrossingsOfCarA();
        Clock::freezeAt($this->at(300));

        $carA = $this->carRow($this->freshScreen(), self::CAR_A);

        $this->assertSame(2, $carA['voltas'], 'three crossings are the pass zero plus two laps');
        $this->assertSame(3, $carA['passagens']);
        foreach ($this->fontes as $fonte) {
            $this->assertSame(3, $carA['contagem_por_fonte'][$fonte->getFonteId()]);
        }
    }

    /**
     * SITUATION: the snapshot is calculated five minutes into the race.
     * Nothing else happens. Ten minutes later a reviewer's screen asks
     * again and is served the SAME snapshot.
     *
     * EXPECTED: the answer carries the snapshot's token as "versao" (a
     * string; it is only ever compared for equality), and the parts that
     * depend on the moment are worked out for that moment: the race clock
     * reads 15 minutes, not 5, although nothing was recalculated.
     */
    public function testTheLiveHalfIsWorkedOutAtTheMomentOfEachAnswer(): void
    {
        $this->raceWithThreeCrossingsOfCarA();
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $snapshot = $this->service->snapshot();

        $atFiveMinutes = StateSnapshot::live($this->service, $snapshot, 'zzt_ana');
        Clock::freezeAt($this->at(900));
        $atFifteenMinutes = StateSnapshot::live($this->service, $snapshot, 'zzt_ana');

        $this->assertIsString($atFiveMinutes['versao']);
        $this->assertSame($snapshot->token, $atFiveMinutes['versao']);
        $this->assertSame($atFiveMinutes['versao'], $atFifteenMinutes['versao'], 'the same snapshot was served twice');
        $this->assertSame($this->at(300), $atFiveMinutes['calculado_em_ms']);

        $this->assertSame(300 * 1000, $atFiveMinutes['corrida']['decorrido_ms']);
        $this->assertSame(900 * 1000, $atFifteenMinutes['corrida']['decorrido_ms']);
        $this->assertSame($this->at(900), $atFifteenMinutes['servidor_agora_ms']);
        $this->assertSame('correndo', $atFifteenMinutes['corrida']['estado']);
        $this->assertArrayHasKey('status', $this->carRow($atFifteenMinutes, self::CAR_A));
    }

    /**
     * SITUATION: a screen already has the current snapshot and polls again.
     *
     * EXPECTED: the answer is the small "inalterado" one. It still carries
     * everything that changes on its own (clock, car statuses, devices,
     * who is looking), and none of the heavy lists.
     */
    public function testAScreenThatIsUpToDateGetsOnlyTheLiveParts(): void
    {
        $this->raceWithThreeCrossingsOfCarA();
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $snapshot = $this->service->snapshot();

        $answer = StateSnapshot::unchanged($this->service, $snapshot, 'zzt_ana');

        $this->assertTrue($answer['inalterado']);
        $this->assertSame($snapshot->token, $answer['versao']);
        foreach (['servidor_agora_ms', 'corrida', 'status_dos_carros', 'dispositivos', 'contato_das_fontes', 'olhando', 'revisores', 'publicacao_desligada'] as $key) {
            $this->assertArrayHasKey($key, $answer);
        }
        foreach (['carros', 'alertas_abertos', 'historico', 'fontes'] as $key) {
            $this->assertArrayNotHasKey($key, $answer);
        }
    }

    /**
     * SITUATION: the organisers run the new counting beside the old
     * spreadsheet and set the publish mode to "desligada": the race is
     * counted and reviewed, but nothing is written to the official results.
     *
     * EXPECTED: the state says so ("publicacao_desligada"), so that the
     * review screen can show it in the status bar. Without it a reviewer
     * would fix a flag, look at the results page, see no change and think
     * the system is broken.
     */
    public function testTheStateSaysWhenPublishingIsSwitchedOff(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->assertFalse($this->freshScreen()['publicacao_desligada'], 'publishing is on by default');

        $this->newRequest();
        $this->service->saveSettings(Settings::fromArray(['modo_publicacao' => Settings::MODO_DESLIGADA]));

        $this->assertTrue($this->freshScreen()['publicacao_desligada']);
    }

    /**
     * SITUATION: Contagem 1 was counted on three phones:
     *   - Ana's is in use; the server heard from it 100 s into the race;
     *   - Bruno pressed "Encerrar meu turno" when Ana took over;
     *   - Carla's was revoked by the controller.
     * The snapshot is calculated 110 s into the race. Then Ana's phone
     * loses its signal. No entry arrives, so nothing is recalculated.
     *
     * EXPECTED:
     *   - at 110 s: Ana "ativo", Bruno "fechado", Carla "revogado";
     *   - at 200 s (quiet for more than `fonte_offline_s` = 60 s): Ana's
     *     phone is "sem_contato", still waited for ("aberto"), and Contagem
     *     1 is shown as offline;
     *   - at 800 s (quiet for more than `dispositivo_ausente_s` = 600 s):
     *     "silencioso", and no longer waited for.
     * All of that from the SAME snapshot: the device states are part of the
     * live half, because a silent phone changes nothing in the database
     * and would otherwise never be noticed.
     */
    public function testSilentClosedAndRevokedDevicesAreShownWithoutARecalculation(): void
    {
        $this->startRace(self::RACE_START_MS);
        $contagem1 = $this->fontes[0];
        $ana = $this->addDevice($contagem1, 'zzt_ana', $this->at(100), null, null);
        $bruno = $this->addDevice($contagem1, 'zzt_bruno', $this->at(90), $this->at(95), null);
        $carla = $this->addDevice($contagem1, 'zzt_carla', $this->at(80), null, $this->at(85));

        Clock::freezeAt($this->at(110));
        $this->recompute();
        $snapshot = $this->service->snapshot();

        $state = StateSnapshot::live($this->service, $snapshot, null);
        $this->assertSame('ativo', $this->device($state, $ana)['situacao']);
        $this->assertTrue($this->device($state, $ana)['aberto']);
        $this->assertSame('fechado', $this->device($state, $bruno)['situacao']);
        $this->assertSame('revogado', $this->device($state, $carla)['situacao']);
        $this->assertFalse($this->sourceRow($state, $contagem1)['stats']['offline']);

        Clock::freezeAt($this->at(200));
        $state = StateSnapshot::live($this->service, $snapshot, null);
        $this->assertSame('sem_contato', $this->device($state, $ana)['situacao']);
        $this->assertTrue($this->device($state, $ana)['aberto'], 'its upload is still waited for');
        $this->assertTrue($this->sourceRow($state, $contagem1)['stats']['offline']);

        Clock::freezeAt($this->at(800));
        $state = StateSnapshot::live($this->service, $snapshot, null);
        $this->assertSame('silencioso', $this->device($state, $ana)['situacao']);
        $this->assertFalse($this->device($state, $ana)['aberto'], 'the system stopped waiting for it');
    }

    private function raceWithThreeCrossingsOfCarA(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->allCountersSee(self::CAR_A, 160);
        $this->allCountersSee(self::CAR_A, 260);
        $this->syncAll($this->at(290));
    }

    /**
     * Puts one phone into the database in a given condition.
     *
     * @param int|null $closedAtMs when its counter pressed "Encerrar meu turno", if they did
     * @param int|null $revokedAtMs when a controller revoked it, if one did
     * @return int the id of the device
     */
    private function addDevice(ContagemFonte $fonte, string $username, int $lastSeenMs, ?int $closedAtMs, ?int $revokedAtMs): int
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->prepare(
            'INSERT INTO contagem_dispositivo
                 (fonte_id, token_hash, usuario, rotulo, criado_em_ms, visto_em_ms, sincronizado_ate_ms, fechado_em_ms, revogado_em_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fonte->getFonteId(), hash('sha256', random_bytes(32)), $username, 'Telefone de teste',
            self::RACE_START_MS, $lastSeenMs, $lastSeenMs, $closedAtMs, $revokedAtMs,
        ]);
        return (int) $connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function device(array $state, int $deviceId): array
    {
        foreach ($state['dispositivos'] as $device) {
            if ($device['id'] === $deviceId) {
                return $device;
            }
        }
        $this->fail('device ' . $deviceId . ' is not in the state');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function sourceRow(array $state, ContagemFonte $fonte): array
    {
        foreach ($state['fontes'] as $row) {
            if ($row['id'] === (int) $fonte->getFonteId()) {
                return $row;
            }
        }
        $this->fail('source ' . $fonte->getNome() . ' is not in the state');
    }
}
