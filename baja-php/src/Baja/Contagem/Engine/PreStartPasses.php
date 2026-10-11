<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;

/**
 * The flag "antes_da_largada": a pass typed shortly before the start.
 *
 * With laps = passes - 1, every car needs its first crossing to be counted
 * as pass zero. If race control records the start a few seconds late, or
 * corrects it to a later time, the first crossing of the cars at the front
 * lands BEFORE the start. A pass before the start cannot be official, so
 * those cars would quietly lose a lap. Instead the group is kept, marked,
 * and flagged here with the suggestion to count it as the car's initial
 * pass.
 *
 * HOW CLOSE IS "SHORTLY". Counters also type during warm-up and the
 * formation lap, and nobody wants a flag for each of those. A group is
 * flagged only when it is no further before the start than the field
 * usually takes to reach the line after it (the median delay of the cars'
 * first passes), and never less than one matching window.
 *
 * That window looks at the whole field, so it is used for FLAGS only.
 * Whether a group before the start is official never depends on it: it is
 * official when a reviewer confirmed it, and only then (see PassRoles).
 *
 * Under the "largada" rule the first crossing is not supposed to be typed,
 * nobody can lose a lap this way, and there is no flag: PassRoles marks
 * such groups as left out by the race rules, and FlagDetector never brings
 * them here.
 */
final class PreStartPasses
{
    /** With fewer cars than this the field's first laps say too little to judge a lap by. */
    private const MIN_CARS_FOR_FIRST_LAP = 3;

    private Settings $settings;
    private RaceTimeline $timeline;

    /** @var array<int, Lap[]> laps under the default counting rule */
    private array $referenceLapsByCar;

    /** The earliest pass time that is still "shortly before the start". */
    private int $windowFromMs = PHP_INT_MAX;

    /** Median duration of the field's first laps, or null when too few cars have one. */
    private ?int $medianFirstLapMs = null;

    /**
     * @param array<int, Lap[]> $referenceLapsByCar each car's official passes under the default rule
     * @param array<int, true> $validCars cars that take part; empty = every car
     */
    public function __construct(Settings $settings, RaceTimeline $timeline, array $referenceLapsByCar, array $validCars)
    {
        $this->settings = $settings;
        $this->timeline = $timeline;
        $this->referenceLapsByCar = $referenceLapsByCar;
        if ($timeline->largadaMs !== null) {
            $this->measureTheField($timeline->largadaMs, $validCars);
        }
    }

    /** True for a pass time close enough to the start to be worth a reviewer's look. */
    public function isShortlyBeforeTheStart(int $tsMs): bool
    {
        return $tsMs >= $this->windowFromMs;
    }

    /** True when a reviewer already gave this car an initial pass from before the start. */
    public function hasInitialPassBeforeTheStart(int $carro): bool
    {
        $passZero = $this->referenceLapsByCar[$carro][0] ?? null;
        return $passZero !== null && $passZero->grupo !== null && $passZero->grupo->antesDaLargada;
    }

    /**
     * The flag for a car's last group before the start.
     *
     * The suggestion is to confirm it, unless that would create a first lap
     * too short to be real: then the group is more likely warm-up, the
     * car's real first crossing is the one already counted, and the
     * reviewer is told so without a suggestion. "Too short" is judged
     * against the field's median first lap, because this early in a race
     * the pace model has nothing to go on yet.
     */
    public function flagFor(PassGroup $group, ConfirmEffect $effect): Flag
    {
        $anchorEntryId = $group->anchorEntryId();
        $flag = new Flag(Flag::ANTES_DA_LARGADA, [$group->carro], $group->entryIds(), (string) $anchorEntryId);
        $flag->tsMs = $group->tsMs;
        $flag->fonteId = count($group->entries) === 1 ? $group->entries[0]->fonteId : null;

        $secondsBefore = (int) round(((int) $this->timeline->largadaMs - $group->tsMs) / 1000);
        // Judged against the passes of the default rule, like every
        // suggestion, so that it reads the same in every publish mode.
        $firstLapMs = $effect->shortestLapIfPassAt($group->carro, $group->tsMs, $group);
        if ($firstLapMs !== null && $this->settings->isImpossiblyShortLap($firstLapMs, $this->medianFirstLapMs)) {
            $flag->motivo = sprintf(
                'O carro %d foi registrado %d s antes da largada, mas contar esta passagem como a inicial criaria uma primeira volta de %d s, curta demais; provavelmente é do aquecimento.',
                $group->carro,
                $secondsBefore,
                (int) round($firstLapMs / 1000)
            );
            return $flag;
        }

        $flag->sugestao = ['tipo' => Decision::CONFIRMAR, 'passagem_id' => $anchorEntryId];
        $flag->motivo = sprintf(
            'O carro %d foi registrado %d s antes da largada. Se a largada foi marcada com atraso, esta é a passagem inicial dele; sem ela o carro fica com uma volta a menos.',
            $group->carro,
            $secondsBefore
        );
        return $flag;
    }

    /**
     * @param array<int, true> $validCars
     */
    private function measureTheField(int $largadaMs, array $validCars): void
    {
        $firstPassDelays = [];
        $firstLapDurations = [];
        foreach ($this->referenceLapsByCar as $carro => $laps) {
            if ($validCars !== [] && !isset($validCars[$carro])) {
                continue;
            }
            $passZero = $laps[0] ?? null;
            if ($passZero === null || $passZero->tsMs < $largadaMs) {
                continue;
            }
            $firstPassDelays[] = $passZero->tsMs - $largadaMs;
            if (isset($laps[1]) && $laps[1]->tempoVoltaMs !== null) {
                $firstLapDurations[] = $laps[1]->tempoVoltaMs;
            }
        }

        $medianDelayMs = (int) round(Stats::median($firstPassDelays) ?? 0);
        $this->windowFromMs = $largadaMs - max($this->settings->janelaMs, $medianDelayMs);
        if (count($firstLapDurations) >= self::MIN_CARS_FOR_FIRST_LAP) {
            $this->medianFirstLapMs = (int) round((float) Stats::median($firstLapDurations));
        }
    }
}
