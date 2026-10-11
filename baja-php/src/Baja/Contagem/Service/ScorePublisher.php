<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\CountResult;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\Settings;
use Baja\Model\Map\InputTableMap;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Copies the engine's numbers into the scoring system: one Input row per
 * car, with the three fields the prova's formulas read.
 *
 *   VOLTAS  official lap count
 *   LAST    time of the last official pass, whole seconds since the start
 *   BEST    best eligible lap in whole seconds, 0 when there is none
 *
 * The rest of the application (results pages, the overlay, the points
 * formulas) only ever looks at Input, so this class is the single bridge
 * between lap counting and everything that was there before it.
 *
 * Three things it is careful about:
 *
 * - It changes only its own three keys inside `dados`, with one JSON_SET
 *   statement per row. The same row holds fields typed by people (DESCLAS),
 *   and a judge saving one at the same instant must never be overwritten.
 * - The writes and the recalculation of points are one transaction. If the
 *   recalculation fails, the counts are rolled back too: a lap count is
 *   never visible without its points.
 * - It stands back completely when the publish mode is "desligada" and
 *   while the race has no start, so that switching a prova to lap counting,
 *   or running beside the old spreadsheets, cannot touch existing results.
 */
final class ScorePublisher
{
    /** Fields this class owns. Entry pages show them read-only for laps provas. */
    public const ENGINE_FIELDS = PublishedValues::KEYS;

    /** How many times points were recalculated in this process. Read by the tests. */
    private static int $refreshCount = 0;

    private Prova $prova;
    private Settings $settings;

    public function __construct(Prova $prova, Settings $settings)
    {
        $this->prova = $prova;
        $this->settings = $settings;
    }

    /**
     * After a quick recount of a few cars: publishes their VOLTAS and LAST.
     * BEST is not known here (it needs the whole race) and is left alone.
     *
     * @param int[] $cars
     */
    public function publishCounts(CountResult $count, array $cars): PublishOutcome
    {
        $outcome = new PublishOutcome();
        if (!$this->mayPublish($count->timeline, $outcome)) {
            return $outcome;
        }
        $valuesByCar = [];
        foreach ($cars as $carro) {
            $valuesByCar[$carro] = [
                PublishedValues::VOLTAS => $count->lapCount($carro),
                PublishedValues::LAST => PublishedValues::lastPassSeconds($count->lastPassMs($carro), $count->timeline),
            ];
        }
        return $this->write($valuesByCar, true, false, $outcome);
    }

    /**
     * After a full recalculation: publishes BEST where it changed. Rows are
     * never created for this; a car without a lap has no best lap.
     *
     * @param array<int, int> $bestSecondsByCar
     */
    public function publishBest(array $bestSecondsByCar, RaceTimeline $timeline): PublishOutcome
    {
        $outcome = new PublishOutcome();
        if (!$this->mayPublish($timeline, $outcome)) {
            return $outcome;
        }
        $valuesByCar = [];
        foreach ($bestSecondsByCar as $carro => $bestSeconds) {
            $valuesByCar[$carro] = [PublishedValues::BEST => $bestSeconds];
        }
        return $this->write($valuesByCar, false, false, $outcome);
    }

    /**
     * Everything for every car, from a full run. Used after a change made by
     * a person, which can touch any car, and by "Republicar tudo".
     *
     * @param bool $rewriteAll write every row and always recalculate points, even if nothing differs
     */
    public function publishAll(EngineResult $result, bool $rewriteAll = false): PublishOutcome
    {
        $outcome = new PublishOutcome();
        if (!$this->mayPublish($result->timeline, $outcome)) {
            return $outcome;
        }
        $valuesByCar = [];
        foreach ($this->teamNumbers() as $carro) {
            $valuesByCar[$carro] = isset($result->cars[$carro])
                ? PublishedValues::forCar($result->cars[$carro], $result->timeline)
                : self::zeros();
        }
        $outcome = $this->write($valuesByCar, true, $rewriteAll, $outcome);
        if ($rewriteAll && !$outcome->refreshRan) {
            $this->refreshPoints($outcome);
        }
        return $outcome;
    }

    /**
     * The check on the fast path. Called with a full run that is known to
     * be up to date (nothing changed since it loaded the race): whatever
     * the scoring system holds that differs from it is wrong and is put
     * right here.
     *
     * A different VOLTAS or LAST means the per-car recount inside an upload
     * and the full run disagreed, which must never happen; each case is
     * written to the error log so that somebody finds out why. A different
     * BEST is normal: the best lap is only ever published from here.
     *
     * Cars without a lap are included, so a best lap left over from laps
     * that were later cancelled is cleared.
     */
    public function reconcile(EngineResult $result): PublishOutcome
    {
        $outcome = new PublishOutcome();
        if (!$this->mayPublish($result->timeline, $outcome)) {
            return $outcome;
        }
        $valuesByCar = [];
        foreach ($this->teamNumbers() as $carro) {
            $valuesByCar[$carro] = isset($result->cars[$carro])
                ? PublishedValues::forCar($result->cars[$carro], $result->timeline)
                : self::zeros();
        }

        foreach ($this->existingData(array_keys($valuesByCar)) as $carro => $dados) {
            foreach ([PublishedValues::VOLTAS, PublishedValues::LAST] as $field) {
                $published = $dados[$field] ?? null;
                $computed = $valuesByCar[$carro][$field];
                if ($published === null || $published != $computed) {
                    error_log(sprintf(
                        'Contagem\ScorePublisher: %s/%s carro %d: %s publicado era %s, o cálculo completo dá %d. Corrigido.',
                        $this->prova->getEventoId(),
                        $this->prova->getProvaId(),
                        $carro,
                        $field,
                        json_encode($published),
                        $computed
                    ));
                    $outcome->correctedCars[] = (int) $carro;
                }
            }
        }
        $outcome->correctedCars = array_values(array_unique($outcome->correctedCars));
        return $this->write($valuesByCar, true, false, $outcome);
    }

    /**
     * Sets every existing row back to zero laps. Used by "Zerar corrida":
     * once the race is wiped there is no start any more, publishing would
     * stand back, and the old numbers would stay on the results page.
     */
    public function publishZeros(): PublishOutcome
    {
        $outcome = new PublishOutcome();
        if (!$this->settings->publishesToScoring()) {
            $outcome->skippedPublishingOff = true;
            return $outcome;
        }
        $valuesByCar = [];
        foreach ($this->teamNumbers() as $carro) {
            $valuesByCar[$carro] = self::zeros();
        }
        return $this->write($valuesByCar, false, false, $outcome);
    }

    public static function refreshCount(): int
    {
        return self::$refreshCount;
    }

    private function mayPublish(RaceTimeline $timeline, PublishOutcome $outcome): bool
    {
        if (!$this->settings->publishesToScoring()) {
            $outcome->skippedPublishingOff = true;
            return false;
        }
        if ($timeline->largadaMs === null) {
            // A prova that was just switched to type "laps" may still hold
            // totals imported the old way. Until race control starts a race
            // here there is nothing of ours to publish, and writing zeros
            // would wipe those totals.
            $outcome->skippedNoStart = true;
            return false;
        }
        return true;
    }

    /**
     * @param array<int, array<string, int>> $valuesByCar car => the fields to set (any subset of the three)
     * @param bool $createMissingRows false when only existing rows may be touched
     */
    private function write(array $valuesByCar, bool $createMissingRows, bool $rewriteAll, PublishOutcome $outcome): PublishOutcome
    {
        $teams = array_fill_keys($this->teamNumbers(), true);
        $existing = $this->existingData(array_keys($valuesByCar));

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            foreach ($valuesByCar as $carro => $values) {
                // A counter's typo may refer to a car that does not exist;
                // Input has a foreign key to equipe, so it cannot hold a score.
                if (!isset($teams[$carro])) {
                    continue;
                }
                $hasRow = array_key_exists($carro, $existing);
                if (!$hasRow) {
                    // A car that has not completed a lap gets no row: an
                    // empty row would list it on the results page as if it
                    // had run.
                    if (!$createMissingRows || ($values[PublishedValues::VOLTAS] ?? 0) === 0) {
                        continue;
                    }
                    $this->insertRow((int) $carro, array_merge(self::zeros(), $values));
                } elseif ($rewriteAll || self::differs($existing[$carro], $values)) {
                    $this->updateRow((int) $carro, $values);
                } else {
                    continue;
                }
                $outcome->changedCars[] = (int) $carro;
            }

            // Recalculating points rereads every Input of the prova, so it
            // only happens when a published number actually moved.
            if ($outcome->anythingChanged()) {
                $this->refreshPoints($outcome);
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
        return $outcome;
    }

    /** @param array<string, int> $values */
    private function updateRow(int $carro, array $values): void
    {
        $assignments = '';
        $params = [];
        foreach ($values as $field => $value) {
            $assignments .= ', ?, CAST(? AS SIGNED)';
            $params[] = '$.' . $field;
            $params[] = $value;
        }
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'UPDATE input
                SET dados = JSON_SET(COALESCE(dados, JSON_OBJECT())' . $assignments . ')
              WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?'
        );
        $statement->execute(array_merge($params, [$this->prova->getEventoId(), $this->prova->getProvaId(), $carro]));
    }

    /** @param array<string, int> $values all three fields */
    private function insertRow(int $carro, array $values): void
    {
        // ON DUPLICATE KEY: a judge may have created the row (saving DESCLAS)
        // between our read and this statement. Then only our keys are set.
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO input (evento_id, prova_id, equipe_id, dados)
             VALUES (?, ?, ?, JSON_OBJECT(?, CAST(? AS SIGNED), ?, CAST(? AS SIGNED), ?, CAST(? AS SIGNED)))
             ON DUPLICATE KEY UPDATE dados = JSON_SET(COALESCE(dados, JSON_OBJECT()),
                 ?, CAST(? AS SIGNED), ?, CAST(? AS SIGNED), ?, CAST(? AS SIGNED))'
        );
        $objectParams = [];
        $setParams = [];
        foreach (PublishedValues::KEYS as $field) {
            array_push($objectParams, $field, $values[$field]);
            array_push($setParams, '$.' . $field, $values[$field]);
        }
        $statement->execute(array_merge(
            [$this->prova->getEventoId(), $this->prova->getProvaId(), $carro],
            $objectParams,
            $setParams
        ));
    }

    /**
     * @param array<string, mixed> $dados what the row holds now
     * @param array<string, int> $values what the engine says
     */
    private static function differs(array $dados, array $values): bool
    {
        foreach ($values as $field => $value) {
            // A missing or non-numeric field counts as different, so a row
            // created by hand ends up with the engine's fields filled in.
            if (!isset($dados[$field]) || !is_numeric($dados[$field])) {
                return true;
            }
            // Loose on purpose: the entry page stores numbers as floats, and
            // 43.0 is the same count as 43.
            if ($dados[$field] != $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int[] $cars
     * @return array<int, array<string, mixed>> existing rows: car => decoded dados
     */
    private function existingData(array $cars): array
    {
        if ($cars === []) {
            return [];
        }
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_id, dados FROM input
              WHERE evento_id = ? AND prova_id = ?
                AND equipe_id IN (' . implode(', ', array_fill(0, count($cars), '?')) . ')'
        );
        $statement->execute(array_merge([$this->prova->getEventoId(), $this->prova->getProvaId()], array_map('intval', $cars)));

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $rows[(int) $row[0]] = (array) json_decode((string) ($row[1] ?? ''), true);
        }
        return $rows;
    }

    /** @return int[] car numbers that can hold a score */
    private function teamNumbers(): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT equipe_id FROM equipe WHERE evento_id = :evento ORDER BY equipe_id'
        );
        $statement->execute([':evento' => $this->prova->getEventoId()]);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string, int> */
    private static function zeros(): array
    {
        return array_fill_keys(PublishedValues::KEYS, 0);
    }

    private function refreshPoints(PublishOutcome $outcome): void
    {
        // The rows were just changed with plain SQL. Any Input object Propel
        // is still holding from earlier in this request is out of date, and
        // refreshVarsAndPontos() would recalculate from it.
        InputTableMap::clearInstancePool();
        $this->prova->refreshVarsAndPontos();
        self::$refreshCount++;
        $outcome->refreshRan = true;
    }
}
