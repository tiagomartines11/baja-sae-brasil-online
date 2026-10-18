<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Service\Clock;

/**
 * The quick count and the full recount must always agree.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * There are two ways the server arrives at "car 11 has 7 laps":
 *
 *   - INCREMENTALLY: each upload recounts only the cars it mentions and
 *     updates only their rows. This is what happens thousands of times in a
 *     race, and it is what the results page shows.
 *   - FROM SCRATCH: "Reconstruir" throws the computed laps away and works
 *     everything out again from the raw entries.
 *
 * If the two could ever differ, the published result would depend on the
 * order in which phones happened to upload, and nobody could explain it in
 * a protest. So this test plays a messy race step by step, the incremental
 * way, and at several points checks that a rebuild from scratch changes
 * nothing.
 *
 * The race is made up by a small random generator with a FIXED seed, so it
 * is the same on every run; a few different seeds are tried.
 */
final class IncrementalEqualsRebuildTest extends DbTestCase
{
    private const CARS = [self::CAR_A, self::CAR_B, self::CAR_C];

    /** @var array<int, string> index of the source => its device token */
    private array $tokens = [];

    /** @var array<int, string[]> index of the source => uuids it has uploaded, for voiding later */
    private array $uploadedUuids = [];

    /** @var array<int, array<string, int>> index of the source => its "??" uploaded so far, uuid => the car that really crossed */
    private array $unreadUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startRace($this->at(0));
        foreach ($this->fontes as $index => $fonte) {
            $this->tokens[$index] = $this->issueToken($fonte);
            $this->uploadedUuids[$index] = [];
            $this->unreadUuids[$index] = [];
        }
    }

    /** @return array<int, array{0: int}> */
    public static function seeds(): array
    {
        return [[1], [2], [3], [4]];
    }

    /**
     * SITUATION: three cars race for fifty minutes. Three counters upload
     * their entries in small batches, in a shuffled order, with the usual
     * mistakes: a counter misses a car, types a number that does not
     * exist, enters a car twice. Along the way a counter voids some of
     * their own entries, and a reviewer cancels an entry, adds a pass by
     * hand, and excludes one counter for ten minutes.
     *
     * EXPECTED: at three checkpoints, and at the end, what the incremental
     * path has published (laps and time of last pass per car) and written
     * to the laps table is exactly what a rebuild from scratch produces.
     *
     * @dataProvider seeds
     */
    public function testAfterAMessyRaceARebuildFromScratchChangesNothing(int $seed): void
    {
        mt_srand($seed);
        $batches = $this->makeUpARace();

        foreach ($batches as $number => $batch) {
            $this->upload($this->tokens[$batch['source']], $batch['entries'], ['queue_empty' => true]);
            foreach ($batch['entries'] as $entry) {
                $this->uploadedUuids[$batch['source']][] = $entry['uuid'];
            }

            if ($number % 7 === 3) {
                $this->aCounterVoidsOneOfTheirEntries($batch['source']);
            }
            if ($number === intdiv(count($batches), 4)) {
                $this->aReviewerDecides(fn(int $anyEntryId) => Decision::anular(0, $anyEntryId));
                $this->assertARebuildChangesNothing('after a reviewer cancelled an entry');
            }
            if ($number === intdiv(count($batches), 2)) {
                $this->aReviewerDecides(fn() => Decision::adicionar(0, self::CAR_B, $this->at(1000)));
                $this->aReviewerDecides(fn() => Decision::excluirFonte(0, (int) $this->fontes[2]->getFonteId(), $this->at(1200), $this->at(1800)));
                $this->assertARebuildChangesNothing('after a pass was added and a counter excluded');
            }
            if ($number === intdiv(3 * count($batches), 4)) {
                $this->assertARebuildChangesNothing('three quarters into the race');
            }
        }

        $this->assertARebuildChangesNothing('at the end');
        $this->assertGreaterThan(5, $this->publishedData(self::CAR_A)['VOLTAS'], 'the made-up race did count laps');
    }

    /**
     * SITUATION: the same kind of race, but each counter presses "??" on
     * about one crossing in eight, and now and then fixes one of them on the
     * phone afterwards, to the right car or (a slip) to a wrong one. Which
     * car a "??" counts for depends on what the OTHER counters read around
     * it, so an upload of one car can change the count of another.
     *
     * EXPECTED: as above: the incremental path, which recounts only the
     * cars an upload touched and the cars around a "??" near it, publishes
     * exactly what a rebuild from scratch gives.
     *
     * @dataProvider seeds
     */
    public function testWithUnreadPassesARebuildFromScratchChangesNothing(int $seed): void
    {
        mt_srand($seed);
        $batches = $this->makeUpARace(12);

        foreach ($batches as $number => $batch) {
            $this->upload($this->tokens[$batch['source']], $batch['entries'], ['queue_empty' => true]);
            foreach ($batch['entries'] as $entry) {
                $this->uploadedUuids[$batch['source']][] = $entry['uuid'];
                if ($entry['car'] === null) {
                    $this->unreadUuids[$batch['source']][$entry['uuid']] = $entry['real_car'];
                }
            }
            if ($number % 5 === 2) {
                $this->aCounterFixesOneOfTheirUnreadPasses($batch['source']);
            }
            if ($number % 9 === 4) {
                $this->aCounterVoidsOneOfTheirEntries($batch['source']);
            }
            if ($number === intdiv(count($batches), 3) || $number === intdiv(2 * count($batches), 3)) {
                $this->assertARebuildChangesNothing('at batch ' . $number);
            }
        }

        $this->assertARebuildChangesNothing('at the end');
        $unread = (int) $this->fetchValue(
            'SELECT COUNT(*) FROM contagem_passagem p JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              WHERE f.evento_id = ? AND p.equipe_numero IS NULL',
            [self::EVENTO_ID]
        );
        $this->assertGreaterThan(5, $unread, 'the made-up race did have "??"');
    }

    private function aCounterFixesOneOfTheirUnreadPasses(int $source): void
    {
        $unread = $this->unreadUuids[$source];
        if ($unread === []) {
            return;
        }
        $uuids = array_keys($unread);
        $uuid = $uuids[mt_rand(0, count($uuids) - 1)];
        $car = mt_rand(1, 4) === 1 ? self::CARS[mt_rand(0, 2)] : $unread[$uuid];
        $this->upload($this->tokens[$source], [], ['fixes' => [['uuid' => $uuid, 'car' => $car]]]);
    }

    /**
     * Every counter's entries for the whole race, cut into upload batches
     * and shuffled a little, as phones with different connections deliver.
     *
     * @param int $unreadPercent how often, in percent, a counter presses "??" instead of typing a car it saw
     * @return array<int, array{source: int, entries: array<int, array<string, mixed>>}>
     */
    private function makeUpARace(int $unreadPercent = 0): array
    {
        $entriesBySource = [0 => [], 1 => [], 2 => []];
        foreach (self::CARS as $position => $car) {
            // Each car laps in about three minutes, at its own pace.
            $lapSeconds = 170 + 15 * $position;
            for ($crossing = 40 + 5 * $position; $crossing < 3000; $crossing += $lapSeconds + mt_rand(-10, 10)) {
                foreach (array_keys($entriesBySource) as $source) {
                    $roll = mt_rand(1, 100);
                    if ($roll <= 6) {
                        continue; // this counter missed the car
                    }
                    $typed = $roll <= 9 ? $car + 1 : $car; // a wrong number now and then
                    $tsMs = $this->at($crossing) + mt_rand(-1500, 1500);
                    if ($unreadPercent > 0 && mt_rand(1, 100) <= $unreadPercent) {
                        // Saw it, could not read it: "??".
                        $payload = $this->entryPayload(self::newUuid(), $car, $tsMs);
                        $payload['car'] = null;
                        $entriesBySource[$source][] = $payload + ['real_car' => $car];
                        continue;
                    }
                    $entriesBySource[$source][] = $this->entryPayload(self::newUuid(), $typed, $tsMs);
                    if ($roll >= 98) {
                        // The same car entered twice, a couple of seconds apart.
                        $entriesBySource[$source][] = $this->entryPayload(self::newUuid(), $typed, $tsMs + 2000);
                    }
                }
            }
        }

        $batches = [];
        foreach ($entriesBySource as $source => $entries) {
            usort($entries, fn(array $a, array $b): int => $a['ts_ms'] <=> $b['ts_ms']);
            foreach (array_chunk($entries, 4) as $chunkNumber => $chunk) {
                // The sort key keeps each phone's batches in order while
                // mixing the three phones unevenly.
                $batches[] = ['key' => $chunkNumber * 10 + mt_rand(0, 25), 'source' => $source, 'entries' => $chunk];
            }
        }
        usort($batches, fn(array $a, array $b): int => [$a['key'], $a['source']] <=> [$b['key'], $b['source']]);
        return $batches;
    }

    private function aCounterVoidsOneOfTheirEntries(int $source): void
    {
        $uuids = $this->uploadedUuids[$source];
        if ($uuids === []) {
            return;
        }
        $uuid = $uuids[mt_rand(0, count($uuids) - 1)];
        $this->upload($this->tokens[$source], [], ['voids' => [['uuid' => $uuid]]]);
    }

    /**
     * A decision made on the review screen: stored, then the whole race is
     * recounted, as the decision endpoint does.
     *
     * @param callable(int): Decision $makeDecision given the id of some stored entry of Contagem 1
     */
    private function aReviewerDecides(callable $makeDecision): void
    {
        $anyEntryId = (int) $this->fetchValue(
            'SELECT passagem_id FROM contagem_passagem WHERE fonte_id = ? ORDER BY passagem_id DESC LIMIT 1',
            [$this->fontes[0]->getFonteId()]
        );
        $this->newRequest();
        $this->service->withLock(function () use ($makeDecision, $anyEntryId): void {
            $this->service->storeDecision($makeDecision($anyEntryId), self::USER_PREFIX . 'revisor', null);
            $this->service->recomputeAndPublish();
        });
    }

    private function assertARebuildChangesNothing(string $when): void
    {
        $incremental = $this->countedState();

        $this->newRequest();
        $this->service->rebuildLaps();

        $this->assertSame($incremental, $this->countedState(), 'incremental and rebuilt counts differ ' . $when);
    }

    /**
     * Everything the count produces: per car the published laps and last
     * pass, and every row of the laps table (its count columns).
     *
     * @return array<string, mixed>
     */
    private function countedState(): array
    {
        $published = [];
        foreach (self::CARS as $car) {
            $dados = $this->publishedData($car);
            $published[$car] = $dados === null ? null : [(int) $dados['VOLTAS'], (int) $dados['LAST']];
        }

        $statement = \Propel\Runtime\Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_numero, numero_volta, ts_ms, fontes, estado FROM contagem_volta
              WHERE evento_id = ? AND prova_id = ? ORDER BY equipe_numero, numero_volta'
        );
        $statement->execute([self::EVENTO_ID, self::PROVA_ID]);
        $laps = array_map(fn(array $row): string => implode(' ', $row), $statement->fetchAll(\PDO::FETCH_NUM));

        return ['published' => $published, 'laps' => $laps];
    }
}
