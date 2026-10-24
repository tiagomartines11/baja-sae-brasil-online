<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\EventStatus;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Spoilers, and who gets to see past them.
 *
 * During an event the organisers can turn "spoilers" on. A column that has
 * a "val_spoilers" formula then shows that instead of its real value (here
 * "?" instead of the points), so the final scores stay a surprise. When the
 * event is finished, spoilers no longer apply.
 *
 * Who is looking matters (ViewerContext):
 *
 *   public      resultados/prova.php and the API. Spoilers apply to
 *               everybody, with no exceptions.
 *   authorized  the judges' copy, prova_juiz.php. Users listed in the
 *               resultado's "authorized_users", and developers, see the
 *               real values.
 *   premiacao   the awards screens, which never applied spoilers.
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class ViewerContextTest extends TestCase
{
    private const CONFIG = '{
        "authorized_users": ["ana", "bruno"],
        "colunas": [
            {"header": "#",      "val": "EquipeNum"},
            {"header": "Pontos", "val": "$PONTOS", "val_spoilers": "\"?\""},
            {"header": "Voltas", "val": "$VOLTAS"}
        ]
    }';

    private static function duringEventWithSpoilers(): EventStatus
    {
        return new EventStatus(spoilers: true, finished: false);
    }

    private static function duringEventWithoutSpoilers(): EventStatus
    {
        return new EventStatus(spoilers: false, finished: false);
    }

    private static function finishedEvent(): EventStatus
    {
        // Spoilers still switched on, but the event is over.
        return new EventStatus(spoilers: true, finished: true);
    }

    /** What the "Pontos" column of car 1 shows to this viewer. */
    private function pontosSeenBy(ViewerContext $viewer): mixed
    {
        $table = Fixtures::build(Fixtures::config(self::CONFIG), [Fixtures::team(1, ['PONTOS' => 87, 'VOLTAS' => 12])], $viewer);
        return $table->rows[0]->display('Pontos');
    }

    // --- The public page -------------------------------------------------

    public function testPublicSeesTheSpoilerValueDuringTheEvent(): void
    {
        $this->assertSame('?', $this->pontosSeenBy(ViewerContext::publicView(self::duringEventWithSpoilers())));
    }

    /** Only columns with a "val_spoilers" are hidden; the others show as usual. */
    public function testColumnsWithoutValSpoilersAreNotHidden(): void
    {
        $viewer = ViewerContext::publicView(self::duringEventWithSpoilers());
        $table = Fixtures::build(Fixtures::config(self::CONFIG), [Fixtures::team(1, ['PONTOS' => 87, 'VOLTAS' => 12])], $viewer);

        $this->assertTrue($table->spoilersHidden);
        $this->assertSame(12, $table->rows[0]->display('Voltas'));
        $this->assertSame(1, $table->rows[0]->display('#'));
    }

    public function testPublicSeesRealValuesWhenSpoilersAreOff(): void
    {
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::publicView(self::duringEventWithoutSpoilers())));
    }

    public function testPublicSeesRealValuesOnceTheEventIsFinished(): void
    {
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::publicView(self::finishedEvent())));
    }

    /**
     * The public view has no user, so "authorized_users" never matters to
     * it. (The public page is cached by Cloudflare for everybody: letting
     * one person past the spoilers there would show them to all.)
     */
    public function testPublicIsNeverLetPastSpoilers(): void
    {
        $viewer = ViewerContext::publicView(self::duringEventWithSpoilers());

        $this->assertTrue($viewer->hidesSpoilers(['ana']));
        $this->assertTrue($viewer->hidesSpoilers(['']));
    }

    // --- The judge page --------------------------------------------------

    public function testAuthorizedUserSeesRealValuesDuringTheEvent(): void
    {
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::authorized(self::duringEventWithSpoilers(), 'ana', false)));
    }

    public function testUserNotInTheListSeesTheSpoilerValue(): void
    {
        $this->assertSame('?', $this->pontosSeenBy(ViewerContext::authorized(self::duringEventWithSpoilers(), 'carla', false)));
    }

    /** The judge page loads a forum session; a visitor who is not logged in has no name in the list. */
    public function testNoUserSeesTheSpoilerValue(): void
    {
        $this->assertSame('?', $this->pontosSeenBy(ViewerContext::authorized(self::duringEventWithSpoilers(), null, false)));
    }

    /** Developers ($_DEV_MODE in bootstrap.php) see the real values without being listed. */
    public function testDeveloperSeesRealValues(): void
    {
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::authorized(self::duringEventWithSpoilers(), 'carla', true)));
    }

    public function testJudgePageWithoutSpoilersShowsRealValuesToEverybody(): void
    {
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::authorized(self::duringEventWithoutSpoilers(), 'carla', false)));
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::authorized(self::finishedEvent(), 'carla', false)));
    }

    // --- The premiação ---------------------------------------------------

    public function testPremiacaoNeverHidesSpoilers(): void
    {
        $this->assertFalse(ViewerContext::premiacao()->hidesSpoilers([]));
        $this->assertSame(87, $this->pontosSeenBy(ViewerContext::premiacao()));
    }

    // --- How a spoiler value is shown -------------------------------------

    /** A list of spoiler formulas gives one line each, and nothing is put in bold. */
    public function testSpoilerListGivesOneLineEach(): void
    {
        $config = Fixtures::config('{"colunas": [{
            "header": "Tempos",
            "val": ["$T1", "$T2"],
            "highlight": "min($T1, $T2)",
            "val_spoilers": ["\"?\"", "$T2"]
        }]}');
        $viewer = ViewerContext::publicView(self::duringEventWithSpoilers());
        $table = Fixtures::build($config, [Fixtures::team(1, ['T1' => 10, 'T2' => 12])], $viewer);

        $this->assertSame('?<br /> 12', $table->rows[0]->display('Tempos'));
    }

    /**
     * The ranking uses what is shown, so during spoilers it ranks by the
     * spoiler value. That is the known bug BAJAAPP-42, kept until its fix:
     * here everybody shows "?", which reads as 0, so all tie at 1st.
     */
    public function testRankingDuringSpoilersUsesTheSpoilerValue(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS", "val_spoilers": "\"?\""}]}');
        $viewer = ViewerContext::publicView(self::duringEventWithSpoilers());
        $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 10]), Fixtures::team(2, ['PONTOS' => 90])], $viewer);

        $this->assertSame([1 => 1, 2 => 1], Fixtures::positions($table->rows));
    }
}
