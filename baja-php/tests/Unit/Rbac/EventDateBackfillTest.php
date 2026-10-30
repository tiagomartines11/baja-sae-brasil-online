<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PropelMigration_1793090000 as EventDatesMigration;

require_once dirname(__DIR__, 3) . '/generated-migrations/PropelMigration_1793090000.php';

/**
 * Reading an existing event's first and last day from its evento.data
 * text, once, when the database is migrated (BAJAAPP-12).
 *
 * BACKGROUND
 *
 * evento.data is text an admin typed for people to read: "20 a 24 de abril
 * de 2022". The migration adds two real date columns, data_inicio and
 * data_fim, and fills them in from that text for the events that already
 * exist. From then on the dates are typed on juiz/evento.php and the text
 * is never read by code again.
 *
 * WHY IT MATTERS FOR SECURITY
 *
 * A role given on an event ends a few days after data_fim. A last day read
 * too early locks judges out during the event; too late (or a typo read as
 * a long event) keeps their access for months. So the reader must get
 * every format the seed really has right, and say "I don't know" (null)
 * for anything else. Such an event keeps empty dates, the migration lists
 * it, and a role on it needs an explicit end until an admin sets them.
 *
 * WHAT IS TESTED
 *
 * The reader alone, without a database. That the migration really adds
 * the columns and stores these answers is tested on MySQL in
 * tests/Rbac/Db/EventDateMigrationTest.php.
 */
final class EventDateBackfillTest extends TestCase
{
    /** Every format in the dev seed (a copy of real events), and the days it gives. */
    public static function realFormats(): array
    {
        return [
            'range in one month' => ['20 a 24 de abril de 2022', '2022-04-20', '2022-04-24'],
            'two-day range' => ['18 a 19 de novembro de 2022', '2022-11-18', '2022-11-19'],
            'leading zero' => ['04 a 06 de novembro de 2022', '2022-11-04', '2022-11-06'],
            'accented month' => ['01 a 05 de março de 2023', '2023-03-01', '2023-03-05'],
            'capitalised month' => ['25 a 28 de Setembro de 2025', '2025-09-25', '2025-09-28'],
            'capitalised accented month' => ['25 a 29 de Março de 2026', '2026-03-25', '2026-03-29'],
        ];
    }

    #[DataProvider('realFormats')]
    public function testEveryFormatInTheSeedIsRead(string $text, string $first, string $last): void
    {
        $this->assertSame([$first, $last], EventDatesMigration::datesFromText($text));
    }

    /**
     * The same text as MySQL hands it over on a connection without a
     * character set: latin1 bytes, not UTF-8. "março" must still be March.
     */
    public function testLatin1TextIsReadToo(): void
    {
        $latin1 = mb_convert_encoding('01 a 05 de março de 2023', 'ISO-8859-1', 'UTF-8');

        $this->assertSame(['2023-03-01', '2023-03-05'], EventDatesMigration::datesFromText($latin1));
    }

    /** Other ways an admin may plausibly have written it. */
    public static function otherFormats(): array
    {
        return [
            'range across months' => ['30 de abril a 2 de maio de 2025', '2025-04-30', '2025-05-02'],
            'range across years, one year written' => ['30 de dezembro a 2 de janeiro de 2026', '2025-12-30', '2026-01-02'],
            'range across years, both written' => ['30 de dezembro de 2025 a 2 de janeiro de 2026', '2025-12-30', '2026-01-02'],
            'single day' => ['15 de agosto de 2025', '2025-08-15', '2025-08-15'],
            'month without cedilla' => ['10 a 12 de marco de 2024', '2024-03-10', '2024-03-12'],
            'ordinal first day' => ['1º a 3 de junho de 2025', '2025-06-01', '2025-06-03'],
            '"e" between two days' => ['18 e 19 de novembro de 2022', '2022-11-18', '2022-11-19'],
            '"até"' => ['20 até 24 de abril de 2022', '2022-04-20', '2022-04-24'],
            'dash' => ['20-24 de abril de 2022', '2022-04-20', '2022-04-24'],
            'numeric' => ['24/04/2022', '2022-04-24', '2022-04-24'],
            'numeric range' => ['20/04/2022 a 24/04/2022', '2022-04-20', '2022-04-24'],
            'entity instead of space' => ['20 a 24&nbsp;de abril de 2022', '2022-04-20', '2022-04-24'],
            'spaces and a full stop around it' => ['  20 a 24 de abril de 2022. ', '2022-04-20', '2022-04-24'],
        ];
    }

    #[DataProvider('otherFormats')]
    public function testOtherReasonableFormatsAreRead(string $text, string $first, string $last): void
    {
        $this->assertSame([$first, $last], EventDatesMigration::datesFromText($text));
    }

    /**
     * Anything that is not just a date in those shapes: null, never a
     * guess. Some of these a person reads easily ("20, 21 e 22 de abril");
     * they are still left for a person, because a reader that guesses at
     * them would also guess wrong at something else.
     */
    public static function notRead(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'to be decided' => ['a definir'],
            'month and year only' => ['abril de 2022'],
            'no year' => ['20 a 24 de abril'],
            'not a month' => ['20 a 24 de abrol de 2022'],
            'a day that does not exist' => ['31 de fevereiro de 2025'],
            'numeric day that does not exist' => ['31/02/2025'],
            'three digits are not a day' => ['124 de abril de 2022'],
            'a list of days' => ['20, 21 e 22 de abril de 2022'],
            'text around the date' => ['Realizado de 20 a 24 de abril de 2022, em SJC'],
            'two dates, not a range' => ['24 de abril de 2022 (adiado para 30 de maio de 2022)'],
            'first day after the last' => ['24 a 20 de abril de 2022'],
            'longer than a month: a typo' => ['20 de abril de 2022 a 24 de abril de 2023'],
        ];
    }

    #[DataProvider('notRead')]
    public function testAnythingElseIsLeftEmpty(?string $text): void
    {
        $this->assertNull(EventDatesMigration::datesFromText($text));
    }
}
