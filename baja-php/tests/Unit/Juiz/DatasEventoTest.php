<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use Baja\Juiz\DatasEvento;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The event's first and last day as typed on juiz/evento.php (BAJAAPP-12).
 *
 * WHY IT MATTERS
 *
 * The last day (data_fim) decides when the roles given on the event end:
 * a few days after it. A last day saved wrong ends judges' access during
 * the event, or keeps it long after. So the page saves only real days, and
 * a last day that is not before the first; anything else shows the form
 * again with a message, and nothing is saved.
 *
 * Empty is fine, for either: an event's dates may not be known yet. Then a
 * role on it simply needs an explicit end.
 */
final class DatasEventoTest extends TestCase
{
    /** What a browser's date input sends for a normal event: both kept as they are. */
    public function testTwoRealDaysAreKept(): void
    {
        $datas = DatasEvento::doFormulario('2026-03-25', '2026-03-29');

        $this->assertTrue($datas->valido());
        $this->assertSame(['2026-03-25', '2026-03-29'], [$datas->inicio, $datas->fim]);
    }

    /** A one-day event: first and last day the same. */
    public function testTheSameDayTwiceIsKept(): void
    {
        $this->assertSame('2026-03-25', DatasEvento::doFormulario('2026-03-25', '2026-03-25')->fim);
    }

    /** Empty fields (not known yet), or a field not sent at all, are saved as empty. */
    public function testEmptyFieldsAreSavedEmpty(): void
    {
        foreach ([['', ''], [null, null], ['  ', ''], ['2026-03-25', ''], ['', '2026-03-29']] as [$inicio, $fim]) {
            $datas = DatasEvento::doFormulario($inicio, $fim);
            $this->assertTrue($datas->valido(), var_export([$inicio, $fim], true));
        }
        $this->assertNull(DatasEvento::doFormulario('2026-03-25', '')->fim);
        $this->assertNull(DatasEvento::doFormulario('', '2026-03-29')->inicio);
    }

    /** A last day before the first is a typo: refused, not saved. */
    public function testALastDayBeforeTheFirstIsRefused(): void
    {
        $datas = DatasEvento::doFormulario('2026-03-29', '2026-03-25');

        $this->assertFalse($datas->valido());
        $this->assertNull($datas->fim, 'Nothing to save when it is refused.');
    }

    /** Anything that is not a real day in the YYYY-MM-DD the date input sends. */
    public static function notARealDay(): array
    {
        return [
            'a day that does not exist' => ['2026-02-30'],
            'month 13' => ['2026-13-01'],
            'the text format' => ['25/03/2026'],
            'words' => ['25 de março de 2026'],
            'a time after it' => ['2026-03-25 10:00'],
            'year zero' => ['0000-03-25'],
            'an array (data_fim[]=x)' => [['2026-03-25']],
        ];
    }

    #[DataProvider('notARealDay')]
    public function testALastDayThatIsNotARealDayIsRefused(mixed $fim): void
    {
        $this->assertFalse(DatasEvento::doFormulario('2026-03-25', $fim)->valido());
    }

    #[DataProvider('notARealDay')]
    public function testAFirstDayThatIsNotARealDayIsRefused(mixed $inicio): void
    {
        $this->assertFalse(DatasEvento::doFormulario($inicio, '2026-03-29')->valido());
    }
}
