<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Torneio;

use Baja\Torneio\Status;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Status: "Congelar Classificação" and "Descongelar
 * Classificação" on juiz/torneio.php (BAJAAPP-37).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Before a tournament's bracket is drawn, the page ranks the qualified cars
 * live: the ranking (the "seeding", CriteriosClassificacaoTest explains it)
 * can still change as scores come in. "Congelar Classificação" (freeze)
 * writes the ranking of that moment into the prova's config, so the bracket
 * is drawn from a list that no longer moves. It lives under "status":
 *
 *     "status": {"frozen": true, "classification": [6, 5, 7, 8]}
 *
 * (car 6 is seed 1, car 5 seed 2...). Unfreezing sets it back to
 * {"frozen": false, "classification": false}.
 *
 * THE BUG
 *
 * A config written without "status" made freezing crash: PHP cannot set
 * "frozen" on a status that does not exist. Now a missing status reads as
 * "not frozen", and freezing creates it.
 *
 * The config is JSON; in PHP it is an object, built here with (object)[...].
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Torneio/StatusTest.php
 */
final class StatusTest extends TestCase
{
    public function testFreezingAConfigWithStatus(): void
    {
        $params = (object)['status' => (object)['frozen' => false, 'classification' => false]];

        Status::congelar($params, '6,5,7,8');

        $this->assertTrue($params->status->frozen);
        $this->assertSame([6, 5, 7, 8], $params->status->classification);
    }

    /** The crash: no "status" at all. Freezing now creates it. */
    public function testFreezingAConfigWithoutStatus(): void
    {
        $params = (object)['type' => 'tournament'];

        Status::congelar($params, '6,5,7,8');

        $this->assertTrue($params->status->frozen);
        $this->assertSame([6, 5, 7, 8], $params->status->classification);
        $this->assertSame('tournament', $params->type);  // the rest is left alone
    }

    /** "status": null, or something that is not an object, is treated as missing. */
    public function testFreezingAConfigWithABrokenStatus(): void
    {
        foreach ([null, false, 'x'] as $quebrado) {
            $params = (object)['status' => $quebrado];

            Status::congelar($params, '3');

            $this->assertTrue($params->status->frozen);
            $this->assertSame([3], $params->status->classification);
        }
    }

    public function testUnfreezingWithAndWithoutStatus(): void
    {
        $com = (object)['status' => (object)['frozen' => true, 'classification' => [6, 5]]];
        $sem = (object)[];

        Status::descongelar($com);
        Status::descongelar($sem);

        foreach ([$com, $sem] as $params) {
            $this->assertFalse($params->status->frozen);
            $this->assertFalse($params->status->classification);
        }
    }

    /** What the page reads to show "Aberto" and the live ranking. */
    public function testAConfigWithoutStatusReadsAsNotFrozen(): void
    {
        $status = Status::de((object)[]);

        $this->assertFalse($status->frozen);
        $this->assertFalse($status->classification);
    }

    /** A frozen status is returned as it is. */
    public function testAFrozenStatusIsLeftAlone(): void
    {
        $params = (object)['status' => (object)['frozen' => true, 'classification' => [6, 5]]];

        $this->assertSame([6, 5], Status::de($params)->classification);
        $this->assertTrue(Status::de($params)->frozen);
    }
}
