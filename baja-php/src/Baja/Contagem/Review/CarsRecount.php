<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\AppliedDecisions;
use Baja\Contagem\Engine\ConfirmEffect;
use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\DecisionApplier;
use Baja\Contagem\Engine\LapCounter;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\EngineInputLoader;

/**
 * A few cars recounted on their own, with everything a timeline needs to
 * explain each of their entries: is it in use, in which pass group, and if
 * not, why not.
 *
 * This is the quick recount an upload makes (what
 * ContagemService::countCarsOnly() does), not a full engine run: a timeline
 * is asked for on every click of a reviewer and must not cost a tenth of a
 * second of whole-race work each time.
 *
 * It asks the engine's LapCounter directly instead of going through
 * countCarsOnly(), because the CountResult that returns only lists the
 * groups that are rows of the lap table. A timeline also has to show the
 * groups the rules of the race leave out (typed inside the start window,
 * passes after the finish) and the double taps the engine dropped.
 *
 * The input is loaded here, once, and kept: the signatures of the entries
 * are computed from the decisions, and those must be the very decisions the
 * count was made from. Two separate reads could straddle another reviewer's
 * click and produce a signature that matches nothing.
 */
final class CarsRecount
{
    public EngineInput $input;
    public RaceTimeline $timeline;
    public SignatureCalculator $signatures;

    /** @var array<int, Decision> id => decision still in force */
    public array $activeDecisions;

    /** @var int[] the cars that were recounted */
    public array $cars;

    /** @var PassGroup[] every pass group of those cars */
    public array $groups = [];

    /** What "confirmar" would do to the count of these cars, as on a flag. */
    public ConfirmEffect $effect;

    /** @var array<int, Source> */
    private array $sourcesById = [];

    private AppliedDecisions $applied;

    /** @var array<int, Entry> entry id => the entry as it stands after people's decisions */
    private array $survivors = [];

    /** @var array<int, true> ids of the entries the engine dropped as repeats */
    private array $autoVoidedEntryIds = [];

    /**
     * @param int[] $cars the cars the caller wants to show
     * @param array{0: int, 1: int}|null $window also recount the cars of passes a reviewer added between these two moments
     */
    public static function load(ContagemService $service, array $cars, ?array $window = null): self
    {
        $loader = new EngineInputLoader($service->getProva());
        $settings = $service->getSettings();
        $cars = array_values(array_unique(array_map('intval', $cars)));

        $input = $loader->loadForCars($settings, $cars);
        // An entry typed as car 5 and moved to car 6 by a reviewer now sits
        // in a pass group of car 6. To show it in its group, car 6 has to
        // be counted too. One more round is enough: the groups of the
        // entries on screen are all that is needed.
        $moreCars = array_diff(self::carsTheEntriesCountFor($input, $window), $cars);
        if ($moreCars !== []) {
            $cars = array_values(array_merge($cars, $moreCars));
            $input = $loader->loadForCars($settings, $cars);
        }
        return new self($input, $cars);
    }

    /**
     * The recount a DECISION is checked against: the cars of the entries the
     * reviewer was looking at, and nothing else.
     *
     * A decision used to be checked against a full engine run, and then the
     * full engine ran again to publish it. The check only needs to know
     * whether those few entries are still as the reviewer saw them, and the
     * signature of an entry comes out the same from this quick recount as
     * from a full run (SignatureCalculator explains why).
     *
     * @param int[] $entryIds stored entries, and passes a reviewer added (ids below zero)
     */
    public static function loadForEntries(ContagemService $service, array $entryIds): self
    {
        $storedIds = array_values(array_filter($entryIds, fn(int $id) => $id > 0));
        $recount = self::load($service, self::carsTypedIn($service->getProva(), $storedIds));

        // A pass a reviewer added exists only as a decision: its car is there.
        $moreCars = [];
        foreach ($recount->activeDecisions as $decision) {
            $isWatchedAddition = $decision->tipo === Decision::ADICIONAR && in_array(-$decision->id, $entryIds, true);
            if ($isWatchedAddition && $decision->carro !== null && !in_array($decision->carro, $recount->cars, true)) {
                $moreCars[] = $decision->carro;
            }
        }
        return $moreCars === [] ? $recount : self::load($service, array_merge($recount->cars, $moreCars));
    }

    /**
     * @param int[] $cars
     */
    private function __construct(EngineInput $input, array $cars)
    {
        $this->input = $input;
        $this->cars = $cars;
        foreach ($input->sources as $source) {
            $this->sourcesById[$source->id] = $source;
        }

        $counter = LapCounter::count($input, $cars);
        // Numbers the official passes: writes each group's numeroVolta.
        $counter->buildLaps();

        $this->timeline = $counter->timeline;
        $this->effect = new ConfirmEffect($counter->timeline, $counter->groupsByCar);
        foreach ($counter->groupsByCar as $groupsOfOneCar) {
            foreach ($groupsOfOneCar as $group) {
                $this->groups[] = $group;
            }
        }
        $this->applied = $counter->applied;
        $this->autoVoidedEntryIds = array_fill_keys($counter->duplicates->autoVoidedEntryIds, true);
        foreach ($this->applied->entries as $entry) {
            $this->survivors[$entry->id] = $entry;
        }

        $this->signatures = new SignatureCalculator($this->groups, $input->decisions);
        $this->activeDecisions = ActiveDecisions::filter($input->decisions);
    }

    public function groupOf(int $entryId): ?PassGroup
    {
        return $this->signatures->groupOf($entryId);
    }

    /** The car an entry counts for after people's decisions; null when a decision took it out. */
    public function survivingEntry(int $entryId): ?Entry
    {
        return $this->survivors[$entryId] ?? null;
    }

    /** @return Entry[] the passes reviewers added for the recounted cars */
    public function addedPasses(): array
    {
        $added = [];
        foreach ($this->survivors as $entry) {
            if ($entry->fonteId === Source::REVISOR_ID && in_array($entry->carro, $this->cars, true)) {
                $added[] = $entry;
            }
        }
        return $added;
    }

    public const ANULADA = 'anulada';
    public const REPETIDA = 'repetida';
    public const ANTES_DA_LARGADA = 'antes_da_largada';
    public const FONTE_INATIVA = 'fonte_inativa';
    public const FONTE_EXCLUIDA = 'fonte_excluida';
    public const FONTE_OBSERVADORA = 'fonte_observadora';
    public const TEMPO_DESCONHECIDO = 'tempo_desconhecido';

    /**
     * Why an entry that is in no pass group is not in use:
     *
     *   anulada             a person voided it
     *   repetida            the engine dropped it as a double tap or a handover duplicate
     *   tempo_desconhecido  its phone had no usable clock: nobody knows when it was typed
     *   antes_da_largada    typed before the start (or the race has none yet)
     *   fonte_observadora   its source was an observer at that moment: compared, never counted
     *   fonte_inativa       its source was switched off at that moment
     *   fonte_excluida      a reviewer excluded its source for that period
     */
    public function reasonNotInUse(Entry $raw): string
    {
        if (isset($this->applied->voidedEntryIds[$raw->id])) {
            return self::ANULADA;
        }
        if (isset($this->autoVoidedEntryIds[$raw->id])) {
            return self::REPETIDA;
        }
        if ($raw->qualidadeTempo === Entry::TEMPO_DESCONHECIDO) {
            // Asked before the start: the time such an entry carries is the
            // phone's own guess and says nothing about when it was typed.
            return self::TEMPO_DESCONHECIDO;
        }
        if ($this->timeline->largadaMs === null || $raw->tsMs < $this->timeline->largadaMs) {
            return self::ANTES_DA_LARGADA;
        }
        $source = $this->sourcesById[$raw->fonteId] ?? null;
        if ($source !== null && $source->wasObservingAt($raw->tsMs) && !$this->applied->isSourceExcludedAt($raw->fonteId, $raw->tsMs)) {
            return self::FONTE_OBSERVADORA;
        }
        if ($source === null || !$source->wasCountingAt($raw->tsMs)) {
            return self::FONTE_INATIVA;
        }
        return self::FONTE_EXCLUIDA;
    }

    /**
     * The numbers a set of stored entries was typed with. One small query:
     * a decision concerns a handful of entries and must not load a race to
     * learn which cars they are about.
     *
     * @param int[] $entryIds
     * @return int[]
     */
    private static function carsTypedIn(\Baja\Model\Prova $prova, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $statement = \Propel\Runtime\Propel::getWriteConnection('resultados')->prepare(
            'SELECT DISTINCT p.equipe_numero
               FROM contagem_passagem p
               JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              WHERE f.evento_id = ? AND f.prova_id = ?
                AND p.passagem_id IN (' . implode(', ', array_fill(0, count($entryIds), '?')) . ')'
        );
        $statement->execute(array_merge([$prova->getEventoId(), $prova->getProvaId()], $entryIds));
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array{0: int, 1: int}|null $window
     * @return int[] the cars the loaded entries count for after decisions, plus the cars of added passes in the window
     */
    private static function carsTheEntriesCountFor(EngineInput $input, ?array $window): array
    {
        $sourcesById = [];
        foreach ($input->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        $cars = [];
        foreach (DecisionApplier::apply($input->entries, $input->decisions, $sourcesById)->entries as $entry) {
            $isAddedPass = $entry->fonteId === Source::REVISOR_ID;
            if (!$isAddedPass) {
                $cars[$entry->carro] = true;
            } elseif ($window !== null && $entry->tsMs >= $window[0] && $entry->tsMs <= $window[1]) {
                $cars[$entry->carro] = true;
            }
        }
        return array_keys($cars);
    }
}
