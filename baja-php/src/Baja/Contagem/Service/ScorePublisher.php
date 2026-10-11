<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\CarState;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Copies the engine's count into the scoring system: one Input row per car,
 * with the three fields the prova's formulas read.
 *
 *   VOLTAS  official lap count
 *   LAST    time of the last counted pass, whole seconds since the start
 *   BEST    best eligible lap in whole seconds, 0 when there is none
 *
 * The rest of the application (results pages, the overlay, the points
 * formulas) only ever looks at Input, so this class is the single bridge
 * between lap counting and everything that was there before it.
 */
final class ScorePublisher
{
    public const FIELD_LAPS = 'VOLTAS';
    public const FIELD_LAST_PASS = 'LAST';
    public const FIELD_BEST_LAP = 'BEST';

    /** Fields this class owns. Entry pages show them read-only for laps provas. */
    public const ENGINE_FIELDS = [self::FIELD_LAPS, self::FIELD_LAST_PASS, self::FIELD_BEST_LAP];

    /** How many times points were recalculated in this process. Read by the tests. */
    private static int $refreshCount = 0;

    private Prova $prova;

    public function __construct(Prova $prova)
    {
        $this->prova = $prova;
    }

    /**
     * @param bool $rewriteAll "Republicar tudo": write every row and always
     *                         recalculate points, even if nothing differs
     */
    public function publish(EngineResult $result, bool $rewriteAll = false): PublishOutcome
    {
        $outcome = new PublishOutcome();
        $largadaMs = $result->timeline->largadaMs;

        if ($largadaMs === null) {
            // A prova that was just switched to type "laps" may still hold
            // totals imported the old way. Until race control starts a race
            // here there is nothing of ours to publish, and writing zeros
            // would wipe those totals.
            $outcome->skippedNoStart = true;
            if ($rewriteAll) {
                $this->refreshPoints($outcome);
            }
            return $outcome;
        }

        $valuesByCar = [];
        foreach ($this->teamNumbers() as $carro) {
            $valuesByCar[$carro] = self::valuesFor($result->cars[$carro] ?? null, $result->timeline);
        }

        $this->writeValues($valuesByCar, $rewriteAll, true, $outcome);

        // Recalculating points rereads every Input of the prova, so it only
        // happens when a published number actually moved.
        if ($rewriteAll || $outcome->anythingChanged()) {
            $this->refreshPoints($outcome);
        }
        return $outcome;
    }

    /**
     * Sets every existing row back to zero laps. Used by "Zerar corrida":
     * once the race is wiped there is no start any more, publish() would
     * stand back, and the old numbers would stay on the results page.
     */
    public function publishZeros(): PublishOutcome
    {
        $outcome = new PublishOutcome();
        $valuesByCar = [];
        foreach ($this->teamNumbers() as $carro) {
            $valuesByCar[$carro] = self::valuesFor(null, null);
        }
        $this->writeValues($valuesByCar, false, false, $outcome);
        if ($outcome->anythingChanged()) {
            $this->refreshPoints($outcome);
        }
        return $outcome;
    }

    public static function refreshCount(): int
    {
        return self::$refreshCount;
    }

    /**
     * @return array<string, int>
     */
    private static function valuesFor(?CarState $state, ?RaceTimeline $timeline): array
    {
        if ($state === null || $timeline === null || $state->voltas === 0) {
            return [self::FIELD_LAPS => 0, self::FIELD_LAST_PASS => 0, self::FIELD_BEST_LAP => 0];
        }
        // The rounding rules live with the engine, where they are tested
        // without a database.
        return PublishedValues::forCar($state, $timeline);
    }

    /**
     * @param array<int, array<string, int>> $valuesByCar
     * @param bool $createMissingRows false when only existing rows may be touched
     */
    private function writeValues(array $valuesByCar, bool $rewriteAll, bool $createMissingRows, PublishOutcome $outcome): void
    {
        $rowsByCar = $this->existingRows();

        foreach ($valuesByCar as $carro => $values) {
            $row = $rowsByCar[$carro] ?? null;

            if ($row === null) {
                // A car that has not completed a lap gets no row: an empty
                // row would show it on the results page as if it had run.
                if (!$createMissingRows || $values[self::FIELD_LAPS] === 0) {
                    continue;
                }
                $row = new Input();
                $row->setEventoId($this->prova->getEventoId());
                $row->setProvaId($this->prova->getProvaId());
                $row->setEquipeId($carro);
                $dados = [];
            } else {
                $dados = (array) $row->getDados();
                if (!$rewriteAll && !self::differs($dados, $values)) {
                    continue;
                }
            }

            // Merge, never replace: the same row holds fields typed by
            // people (DESCLAS, for one) that the engine knows nothing about.
            $row->setDados(array_merge($dados, $values));
            $row->save();
            $outcome->changedCars[] = $carro;
        }
    }

    /**
     * @param array<string, mixed> $dados what the row holds now
     * @param array<string, int> $values what the engine says
     */
    private static function differs(array $dados, array $values): bool
    {
        foreach ($values as $field => $value) {
            // A missing or non-numeric field counts as different, so a row
            // created by hand ends up with all three fields filled in.
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

    /** @return array<int, Input> existing rows of this prova keyed by car number */
    private function existingRows(): array
    {
        $rows = InputQuery::create()
            ->filterByEventoId($this->prova->getEventoId())
            ->filterByProvaId($this->prova->getProvaId())
            ->find();

        $rowsByCar = [];
        foreach ($rows as $row) {
            $rowsByCar[(int) $row->getEquipeId()] = $row;
        }
        return $rowsByCar;
    }

    /**
     * Car numbers that can hold a score. A counter's typo may refer to a car
     * that does not exist; Input has a foreign key to equipe, so such a
     * number could not be saved and is simply not published.
     *
     * @return int[]
     */
    private function teamNumbers(): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT equipe_id FROM equipe WHERE evento_id = :evento ORDER BY equipe_id'
        );
        $statement->execute([':evento' => $this->prova->getEventoId()]);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function refreshPoints(PublishOutcome $outcome): void
    {
        $this->prova->refreshVarsAndPontos();
        self::$refreshCount++;
        $outcome->refreshRan = true;
    }
}
