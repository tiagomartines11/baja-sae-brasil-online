<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Posicoes\ExternalPositions;
use PHPUnit\Framework\TestCase;

/**
 * A position taken from a resultado (BAJAAPP-102) is the one its results
 * page shows, for every resultado of the dev seed.
 *
 * BACKGROUND
 *
 * A prova's "posicoes" can say {"P_TRA": {"resultado": "26BR_TRA"}}: then
 * $P_TRA is the team's position in that results page. It must be exactly
 * the position the page shows, with all its rules: ties, tiebreaks,
 * filters, disqualified teams, teams with no value.
 *
 * WHAT IS COMPARED
 *
 * tests/results-snapshots/ holds every results page of the seed, as the
 * site printed it (docs/results-snapshots.md): the HTML of each cell. The
 * "Pos" column there shows "1º", "12º" or "–"; the "#" column is the car
 * number. This test reads that column off every captured page that has
 * one, and checks that ExternalPositions gives every car the same
 * position (a car with several rows, one per run, gets its best).
 *
 * The snapshots are an independent witness: they are the printed pages,
 * checked against the site by scripts/results-snapshot.php --check.
 *
 * HOW TO RUN
 *
 * Read-only, but needs the dev seed in the database. In the dev container:
 *
 *     docker compose exec baja-app vendor/bin/phpunit --testsuite prova-db
 *
 * Skipped without the database variables.
 */
final class ExternalPositionsAgainstSeedTest extends TestCase
{
    /** A printed position: "12º", the 1st place's hidden text, or "–" for none. */
    private const POSITION = '/^(?:<p style="color:transparent">)?(\d+)º(?:<\/p>)?$|^–$/u';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('Needs the dev seed: set the database variables.');
        }
        require_once dirname(__DIR__, 3) . '/src/config.php';
    }

    public function testEveryPositionIsTheOneThePageShows(): void
    {
        $compared = 0;
        foreach (glob(dirname(__DIR__, 2) . '/results-snapshots/*/*.json') as $file) {
            $snapshot = json_decode((string)file_get_contents($file), true);
            if (($snapshot['page'] ?? null) !== 'resultados/prova.php' || ($snapshot['spoilers'] ?? true) !== false) {
                continue;
            }
            $shown = self::shownPositions($snapshot['tables'][0] ?? []);
            if ($shown === null) {
                continue;  // no "Pos" column on that page
            }

            $positions = ExternalPositions::ofResultado($snapshot['evento'], $snapshot['id']);
            foreach ($shown as $car => $position) {
                $this->assertSame($position, $positions[$car] ?? null, $snapshot['id'] . ', car ' . $car);
            }
            $this->assertSame([], array_diff_key($positions, $shown), $snapshot['id'] . ': only cars on the page');
            $compared++;
        }

        // The seed has about 250 such pages; far fewer means the seed is not there.
        $this->assertGreaterThan(200, $compared, 'pages compared');
    }

    /**
     * The best position shown for each car, from the one column whose every
     * cell is a printed position; null when the page has no such column.
     *
     * @param array{columns?: list<string>, rows?: list<array<string, string>>} $table
     * @return array<int, int|null>|null car number => position
     */
    private static function shownPositions(array $table): ?array
    {
        $rows = $table['rows'] ?? [];
        $column = null;
        foreach ($table['columns'] ?? [] as $header) {
            $all = $rows !== [];
            foreach ($rows as $row) {
                $all = $all && preg_match(self::POSITION, (string)($row[$header] ?? '')) === 1;
            }
            if ($all) {
                $column = $header;
                break;
            }
        }
        if ($column === null) {
            return null;
        }

        $shown = [];
        foreach ($rows as $row) {
            preg_match(self::POSITION, $row[$column], $match);
            $position = isset($match[1]) ? (int)$match[1] : null;
            $car = (int)$row['#'];
            $best = $shown[$car] ?? null;
            $shown[$car] = $best === null ? $position : ($position === null ? $best : min($best, $position));
        }
        return $shown;
    }
}
