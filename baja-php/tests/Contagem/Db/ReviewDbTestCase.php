<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\DecisionValidator;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Model\ContagemDecisaoQuery;

/**
 * Base class of the tests about the review screen and race control.
 *
 * It adds two things to DbTestCase:
 *
 * 1. The exit guard (see ReviewExitGuard): if the code under test ends the
 *    PHP process, the run fails loudly instead of stopping in silence.
 *
 * 2. Helpers that play the part of a reviewer's browser: asking for the
 *    state of the race and sending a decision, exactly as the files
 *    juiz/contagem_estado.php and juiz/contagem_decidir.php do.
 *
 * WORDS USED IN THESE TESTS
 *
 *   entry (passagem)   one record typed by a counter: "car 11 crossed now"
 *   pass group         the entries of different counters about one crossing
 *   pass zero          a car's first crossing; the counting line is after
 *                      the start line, so it completes no lap
 *   flag (alerta)      something the system wants a person to look at
 *   decision           a correction made by a reviewer, stored as a new
 *                      record (nothing is ever edited or deleted)
 *   signature          a fingerprint of the entries a reviewer was looking
 *                      at; sent back with each decision so the server can
 *                      tell whether they changed in the meantime
 *   snapshot           the result of the last full calculation, which the
 *                      server hands out for a few seconds instead of
 *                      calculating again for every question
 */
abstract class ReviewDbTestCase extends DbTestCase
{
    protected function setUp(): void
    {
        ReviewExitGuard::testStarted(static::class . '::' . $this->name());
        try {
            parent::setUp();
        } catch (\Throwable $notStarted) {
            // Skipped (no database) or failed before it began: nothing is
            // running any more, so the guard must not cry wolf.
            ReviewExitGuard::testFinished();
            throw $notStarted;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ReviewExitGuard::testFinished();
    }

    /**
     * What a reviewer's browser receives when it asks for the state. The
     * race is recalculated first, so the state includes everything the test
     * put into the database so far.
     *
     * @return array<string, mixed>
     */
    protected function freshScreen(string $username = 'zzt_revisor'): array
    {
        $this->recompute();
        return StateSnapshot::live($this->service, $this->service->snapshot(), $username);
    }

    /**
     * Sends a decision the way the review screen does.
     *
     * @param array<string, mixed> $decision e.g. ['tipo' => 'anular', 'passagem_id' => 12]
     * @param int[] $watchedEntryIds the entries the reviewer was looking at
     * @param string|null $signature the fingerprint of those entries on the reviewer's screen
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function decide(array $decision, array $watchedEntryIds = [], ?string $signature = null, string $username = 'zzt_revisor'): array
    {
        $this->newRequest();
        return DecisionValidator::decide($this->service, [
            'decisao' => $decision,
            'passagem_ids' => $watchedEntryIds,
            'assinatura' => $signature,
            'ultima_decisao_id' => null,
        ], $username);
    }

    /**
     * Sends a decision about one entry, with the fingerprint that entry has
     * right now (as if the reviewer had just loaded it and clicked).
     *
     * @param array<string, mixed> $decision
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function decideAboutEntry(array $decision, int $entryId, string $username = 'zzt_revisor'): array
    {
        return $this->decide($decision, [$entryId], $this->currentSignatureOf([$entryId]), $username);
    }

    /**
     * The fingerprint the server computes right now for a set of entries.
     *
     * @param int[] $entryIds
     */
    protected function currentSignatureOf(array $entryIds): string
    {
        $this->newRequest();
        $input = $this->service->loadInput();
        return DecisionValidator::signature(\Baja\Contagem\Engine\Engine::run($input), $entryIds, $input->decisions);
    }

    /** How many decisions are stored for the test prova, undone ones included. */
    protected function countDecisions(): int
    {
        return ContagemDecisaoQuery::create()
            ->filterByEventoId(self::EVENTO_ID)
            ->filterByProvaId(self::PROVA_ID)
            ->count();
    }

    /**
     * The row of one car in the car table of a state.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    protected function carRow(array $state, int $car): array
    {
        foreach ($state['carros'] as $row) {
            if ($row['carro'] === $car) {
                return $row;
            }
        }
        $this->fail('car ' . $car . ' is not in the car table');
    }

    /**
     * Three counters record one crossing of a car, a second apart from each
     * other, which is how it looks in a real race.
     *
     * @return int[] the ids of the three entries, in the order of the sources
     */
    protected function allCountersSee(int $car, int $secondsAfterStart): array
    {
        $ids = [];
        foreach ($this->fontes as $index => $fonte) {
            $ids[] = $this->addEntry($fonte, $car, $this->at($secondsAfterStart) + $index * 1000);
        }
        return $ids;
    }
}
