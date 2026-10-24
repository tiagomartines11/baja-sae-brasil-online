<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit;

use Baja\Model\Evento;
use Baja\Model\EventoQuery;
use Baja\ResultsSnapshot\Capture\DuringEventView;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot tool never writes to the database. This file checks the
 * parts of that promise that need no database; tests/ResultsSnapshot/Db/
 * NoWritesTest.php checks it against a real one.
 *
 * WHY THIS MATTERS
 *
 * The tool is meant to run against a copy of production before a release.
 * A tool that "only writes inside a transaction it rolls back" is one bug
 * away from changing published results. So it does not write at all, and
 * these tests keep it that way.
 */
final class ReadOnlyToolTest extends TestCase
{
    protected function tearDown(): void
    {
        DuringEventView::setCurrentEvent(null);
    }

    public function testTheSpoilerViewChangesTheEventInMemoryOnly(): void
    {
        // The pages ask EventoQuery::getCurrentEvent() whether spoilers are
        // on. DuringEventView puts an Evento object with the two flags
        // flipped where that method looks, without saving it anywhere.
        $evento = new Evento();
        $evento->setEventoId('99SU');
        $evento->setSpoilers(false);
        $evento->setFinalizado(true);

        DuringEventView::showObject($evento);

        $current = EventoQuery::getCurrentEvent();
        $this->assertSame($evento, $current, 'The pages now get this very object...');
        $this->assertTrue($current->getSpoilers(), '...with spoilers on...');
        $this->assertFalse($current->getFinalizado(), '...and the event not finished.');
        $this->assertTrue($current->isNew(), 'It was never saved: Propel still sees it as a new, unsaved object.');
    }

    public function testTheToolsCodeContainsNoWrites(): void
    {
        // A plain text search of every file of the tool, for anything that
        // writes to the database: Propel's save() and delete(), the methods
        // of the app that save (refreshVarsAndPontos, updateTotals), and
        // write statements in SQL. The read-only MySQL session would make
        // any of these fail anyway; this test makes them fail in review.
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/src/Baja/ResultsSnapshot/*.php') ?: [],
            glob($root . '/src/Baja/ResultsSnapshot/*/*.php') ?: [],
            glob($root . '/scripts/results-snapshot.php') ?: [],
            glob($root . '/scripts/results-snapshot/*.php') ?: [],
        );
        $this->assertGreaterThan(10, count($files), 'The search found the tool.');

        $writes = '/->\s*(save|delete)\s*\(|refreshVarsAndPontos\s*\(|updateTotals\s*\(|\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\s+[A-Z`a-z_]/';
        foreach ($files as $file) {
            // Comments may talk about these methods; only the code counts.
            $code = '';
            foreach (token_get_all((string)file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            $this->assertDoesNotMatchRegularExpression($writes, $code, basename($file) . ' must not write to the database.');
        }
    }
}
