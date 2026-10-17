<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Access\CounterLinks;
use Baja\Contagem\Access\PitLoggers;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemDispositivoQuery;
use Baja\Model\UserQuery;
use InvalidArgumentException;

/**
 * The "Box e abastecimento" section of the lap counting's "Usuários" page
 * (round 4h): the prova's controller gives and takes the pit log.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The people at the pit lane are often not the counters, so the pit log has
 * a permission code of its own, "{evento}_{prova}_BOX" (here 00NE_ZZL_BOX).
 * Until now only an administrator could tick it, on the user's page. The
 * controller of a prova is usually not an administrator, and hands out the
 * phones on race morning; so the same page where they link counters to
 * sources can now add and remove pit loggers (Access\PitLoggers).
 *
 * That page may touch ONE code of a user's row: this prova's pit log code.
 * Everything else in the row (counting, other provas, admin) must survive.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class SourceAdminPitLoggersTest extends ReviewDbTestCase
{
    private const PIT_CODE = self::EVENTO_ID . '_' . self::PROVA_ID . '_BOX';
    private const COUNTER_CODE = self::EVENTO_ID . '_' . self::PROVA_ID . '_CONT';

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
        // The forum knows these names, and nobody else. (Test users start
        // with "zzt_" so that the test world removes them afterwards, even
        // one created by mistake.)
        CounterLinks::setForumLookup(fn(string $name) => in_array($name, ['zzt_ana', 'zzt_bia'], true) ? ['username' => $name] : null);
    }

    /**
     * SITUATION: race morning. The controller adds "zzt_ana", who already
     * has an account here with codes of another prova (00NE_XYZ) and of the
     * site's index, to the pit log. Then adds her a second time by mistake.
     * Then adds "zzt_bia", who never used this system.
     *
     * WHAT WENT WRONG: there was no way to do this without an
     * administrator.
     *
     * EXPECTED: Ana's row gains exactly the pit code and keeps every other
     * code; the second add changes nothing (the code is there once). Bia's
     * row is created with "index" and the pit code. Both are listed.
     */
    public function testAddingGrantsOnlyThePitCodeOnceAndCreatesTheRowWhenTheForumKnowsTheName(): void
    {
        $this->createUser('zzt_ana', ['index', '00NE_XYZ']);

        PitLoggers::add($this->prova, 'zzt_ana');
        PitLoggers::add($this->prova, 'zzt_ana');
        PitLoggers::add($this->prova, '  zzt_bia ');

        $this->assertSame(['index', '00NE_XYZ', self::PIT_CODE], $this->permissionsOf('zzt_ana'));
        $this->assertSame(['index', self::PIT_CODE], $this->permissionsOf('zzt_bia'));
        $this->assertSame(['zzt_ana', 'zzt_bia'], array_column(PitLoggers::listForProva($this->prova), 'usuario'));
    }

    /**
     * SITUATION: the controller types a name the forum does not know (a
     * typo), or nothing at all.
     *
     * EXPECTED: refused with a sentence in Portuguese, and no row created:
     * a typo must not create an account nobody can log in to.
     */
    public function testAnUnknownUserIsRefusedAndNoRowIsCreated(): void
    {
        foreach (['zzt_ninguem', '   '] as $typed) {
            try {
                PitLoggers::add($this->prova, $typed);
                $this->fail('"' . $typed . '" was accepted');
            } catch (InvalidArgumentException $refusal) {
                $this->assertMatchesRegularExpression('/não existe no fórum|Informe o nome/', $refusal->getMessage());
            }
        }
        $this->assertNull(UserQuery::create()->findOneByUsername('zzt_ninguem'));
        $this->assertSame([], PitLoggers::listForProva($this->prova));
    }

    /**
     * SITUATION: "zzt_ana" logs the pit lane AND counts on Contagem 1. Her
     * pit phone has logged a stop. During the race the controller removes
     * her from "Box e abastecimento" (she is needed elsewhere).
     *
     * WHAT WENT WRONG: only an administrator could take the code, and her
     * pit phone kept its token: it went on uploading until its user's code
     * was checked on the next stop.
     *
     * EXPECTED: only the pit code goes. Her counter code, her other codes
     * and her link to Contagem 1 stay, and so does her counting phone. Her
     * pit phone is revoked at once: its next upload is refused (the token is
     * no longer valid) and nothing of it is stored; what it stored before
     * stays. She is no longer listed.
     */
    public function testRemovingTakesOnlyThePitCodeAndStopsHerPitPhoneButNotHerCounting(): void
    {
        $this->createUser('zzt_ana', ['index', '00NE_XYZ']);
        PitLoggers::add($this->prova, 'zzt_ana');
        CounterLinks::link($this->fontes[0], 'zzt_ana', 'zzt_controle');
        $countingToken = $this->issueToken($this->fontes[0], 'zzt_ana');
        $pitToken = $this->issuePitLogToken('zzt_ana');
        $first = $this->boxEntry(self::CAR_A, 120);
        $this->assertSame([$first['uuid']], $this->upload($pitToken, [], ['pit_events' => [$first]])['body']['stored_uuids']);

        PitLoggers::remove($this->prova, 'zzt_ana');

        $this->assertSame(['index', '00NE_XYZ', self::COUNTER_CODE], $this->permissionsOf('zzt_ana'));
        $boxSource = \Baja\Contagem\Sync\DeviceTokens::boxSourceFor($this->prova);
        $this->assertSame(0, ContagemDispositivoQuery::create()
            ->filterByFonteId($boxSource->getFonteId())->filterByUsuario('zzt_ana')->filterByRevogadoEmMs(null)->count(),
            'her pit phone is revoked at once, not only refused at its next stop');
        $this->assertSame(['zzt_ana'], array_column(CounterLinks::listForSource($this->fontes[0]), 'usuario'), 'still counting on Contagem 1');
        $this->assertSame([], PitLoggers::listForProva($this->prova));

        $second = $this->boxEntry(self::CAR_B, 180);
        $refused = $this->upload($pitToken, [], ['pit_events' => [$second]]);
        $this->assertContains($refused['status'], [401, 403], 'the pit phone is refused');
        $this->assertSame(1, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, 'box_entrada']
        ), 'the first stop stays, the second was not stored');
        $this->assertSame(1, ContagemDispositivoQuery::create()
            ->filterByFonteId($this->fontes[0]->getFonteId())->filterByUsuario('zzt_ana')->filterByRevogadoEmMs(null)->count(),
            'her counting phone is not revoked');
        $counted = $this->upload($countingToken, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(300))]);
        $this->assertSame(200, $counted['status'], 'and it still uploads');
    }

    /**
     * SITUATION: the controller removed "zzt_ana" from the pit log by
     * mistake, and adds her again.
     *
     * EXPECTED: she gets the code back and may open a new pit phone: her
     * revoked device no longer keeps her out, as when a counter is linked
     * again.
     */
    public function testAddingSomebodyAgainLetsThemBackIn(): void
    {
        $this->createUser('zzt_ana', ['index']);
        PitLoggers::add($this->prova, 'zzt_ana');
        $this->issuePitLogToken('zzt_ana');
        PitLoggers::remove($this->prova, 'zzt_ana');
        $boxSource = \Baja\Contagem\Sync\DeviceTokens::boxSourceFor($this->prova);
        $this->assertTrue(\Baja\Contagem\Sync\DeviceTokens::isBlocked($boxSource, 'zzt_ana'));

        PitLoggers::add($this->prova, 'zzt_ana');

        $this->assertContains(self::PIT_CODE, $this->permissionsOf('zzt_ana'));
        $this->assertFalse(\Baja\Contagem\Sync\DeviceTokens::isBlocked($boxSource, 'zzt_ana'));
    }

    /** @return string[] */
    private function permissionsOf(string $username): array
    {
        TestWorld::forgetLoadedRows();
        return array_values((array) UserQuery::create()->findOneByUsername($username)->getPermissions());
    }

    private function boxEntry(int $car, int $seconds): array
    {
        return [
            'uuid' => self::newUuid(),
            'car' => $car,
            'tipo' => 'box_entrada',
            'ts_ms' => $this->at($seconds),
            'time_quality' => 'exato',
            'ts_device_ms' => $this->at($seconds),
        ];
    }
}
