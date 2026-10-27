<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use Baja\Rbac\Expiry\EventDateParser;
use PHPUnit\Framework\TestCase;

/**
 * Reading an event's last day from evento.data (BAJAAPP-12).
 *
 * WHY IT MATTERS
 *
 * A role given on an event (or one of its provas or queues) ends a few
 * days after the event's last day, so that judges do not keep access to
 * an event forever. That last day is read from evento.data, which is free
 * text an admin typed for people to read.
 *
 * Reading it wrong is a security problem either way: too early and
 * judges are locked out during the event; too late and access lingers.
 * So the parser must get every format the database really has right, and
 * say "I don't know" (null) for anything else. GrantExpiry then refuses to
 * guess and asks for an explicit end.
 */
final class EventDateParserTest extends TestCase
{
    /** Every format in the dev seed (a copy of real events), and the day it ends. */
    public static function realFormats(): array
    {
        return [
            'range in one month' => ['20 a 24 de abril de 2022', '2022-04-24'],
            'two-day range' => ['18 a 19 de novembro de 2022', '2022-11-19'],
            'leading zero' => ['04 a 06 de novembro de 2022', '2022-11-06'],
            'accented month' => ['01 a 05 de março de 2023', '2023-03-05'],
            'capitalised month' => ['25 a 28 de Setembro de 2025', '2025-09-28'],
            'capitalised accented month' => ['25 a 29 de Março de 2026', '2026-03-29'],
        ];
    }

    /** @dataProvider realFormats */
    public function testEveryFormatInTheSeedIsRead(string $text, string $lastDay): void
    {
        $this->assertSame($lastDay, EventDateParser::lastDay($text)?->format('Y-m-d'));
    }

    /** Other ways an admin may plausibly write it. */
    public static function otherFormats(): array
    {
        return [
            'range across months' => ['30 de abril a 2 de maio de 2025', '2025-05-02'],
            'single day' => ['15 de agosto de 2025', '2025-08-15'],
            'month without cedilla' => ['10 a 12 de marco de 2024', '2024-03-12'],
            'ordinal first day' => ['1º de junho de 2025', '2025-06-01'],
            'numeric' => ['24/04/2022', '2022-04-24'],
            'numeric range' => ['20/04/2022 a 24/04/2022', '2022-04-24'],
            'entity instead of space' => ['24&nbsp;de abril de 2022', '2022-04-24'],
            'text around it' => ['Realizado de 20 a 24 de abril de 2022, em SJC', '2022-04-24'],
        ];
    }

    /** @dataProvider otherFormats */
    public function testOtherReasonableFormatsAreRead(string $text, string $lastDay): void
    {
        $this->assertSame($lastDay, EventDateParser::lastDay($text)?->format('Y-m-d'));
    }

    /** Anything without a full, real date: null, never a guess. */
    public static function unreadable(): array
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
            'three digits are not a day' => ['124 de abril de 2022 (?)'],
        ];
    }

    /** @dataProvider unreadable */
    public function testAnythingElseIsUnknown(?string $text): void
    {
        $this->assertNull(EventDateParser::lastDay($text));
    }
}
