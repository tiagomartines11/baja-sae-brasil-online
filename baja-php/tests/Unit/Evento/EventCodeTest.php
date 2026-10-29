<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Evento;

use Baja\Evento\EventCode;
use PHPUnit\Framework\TestCase;

/**
 * What counts as an event code (BAJAAPP-74).
 *
 * BACKGROUND
 *
 * Every event has a code, its evento_id: two digits for the year and two
 * letters (or a letter and a digit) for the competition. 26BR is the 2026
 * Baja SAE BRASIL national; 25AR, 26EQ and 24H2 are other competitions of
 * those years.
 *
 * The command-line tools and the backup scripts check a code before using
 * it, because the code becomes a folder name. Each of them used to have
 * its own copy of the rule, and every copy listed the letters it knew:
 * BR, SE, SU, NE. So the events with other letters were refused
 * everywhere: results-snapshot.php said "Not an event code: 25AR", and the
 * nightly archive of such an event failed every night.
 *
 * Now there is one rule, in Baja\Evento\EventCode, that every PHP tool
 * calls; the shell scripts call one function with the same rule
 * (backup_is_event_code in baja-infra/scripts/backup-lib.sh). One rule in
 * one place cannot drift from itself: change it there and every tool
 * changes with it. And it does not list the letters, so a new competition
 * needs no change at all.
 *
 * The rule says only what a code LOOKS like. Whether an event with that
 * code exists is for the database to say (the tools then answer "No such
 * event").
 */
final class EventCodeTest extends TestCase
{
    /** Every competition seen on production, in a code of some year. */
    public function testEveryCompetitionSeenInProductionIsAnEventCode(): void
    {
        foreach (['22BR', '23SE', '24SU', '25NE', '25AR', '24FA', '23FO', '26EQ', '24H2'] as $code) {
            $this->assertTrue(EventCode::isValid($code), $code);
        }
    }

    /**
     * The rule does not list the competitions, so a new one is a code too.
     * Digits are allowed in the second half, as in H2.
     */
    public function testANewCompetitionIsAnEventCodeToo(): void
    {
        foreach (['27ZZ', '30X1', '2600', '00AA'] as $code) {
            $this->assertTrue(EventCode::isValid($code), $code);
        }
    }

    /**
     * What is not a code. The last few are paths: a code is used as a folder
     * name, so anything that could climb out of the folder (or add one
     * inside it) must be refused, whatever comes around it.
     */
    public function testWhatIsNotAnEventCode(): void
    {
        $notCodes = [
            ''               => 'empty',
            '26br'           => 'lowercase: evento_id is stored in capitals',
            '26Br'           => 'half lowercase',
            '226BR'          => 'three digits',
            '6BR'            => 'one digit',
            '26B'            => 'one letter',
            '26BRX'          => 'three letters',
            'BR26'           => 'letters first',
            ' 26BR'          => 'a space before',
            '26BR '          => 'a space after',
            "26BR\n"         => 'a newline after ($ alone would let it through)',
            '26-B'           => 'punctuation',
            '26ÁR'           => 'an accented capital',
            '２６BR'          => 'full-width digits',
            '26BR/../x'      => 'a path that climbs out',
            '../x'           => 'a path',
            '26BR/x'         => 'a sub-folder',
            '..'             => 'the parent folder',
        ];
        foreach ($notCodes as $code => $why) {
            $this->assertFalse(EventCode::isValid((string)$code), $why . ': "' . $code . '"');
        }
    }
}
