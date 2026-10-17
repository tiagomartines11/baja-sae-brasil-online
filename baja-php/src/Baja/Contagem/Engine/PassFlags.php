<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Writes the flags that are about one pass group (or one entry) taken on
 * its own: what it is about and the sentence the reviewer reads.
 *
 * WHEN each of them is raised is decided by FlagDetector; this class only
 * knows how to word them. Most have no suggestion: the engine can point at
 * the problem but cannot know which record is the wrong one.
 */
final class PassFlags
{
    /** @var array<int, Source> */
    private array $sourcesById;

    /**
     * @param array<int, Source> $sourcesById
     */
    public function __construct(array $sourcesById)
    {
        $this->sourcesById = $sourcesById;
    }

    /** A number that is no team at the event. */
    public function unknownCar(PassGroup $group): Flag
    {
        $flag = $this->aboutGroup(Flag::CARRO_DESCONHECIDO, $group);
        $flag->fonteId = count($group->entries) === 1 ? $group->entries[0]->fonteId : null;
        $flag->motivo = sprintf('O número %d não é de nenhuma equipe presente no evento.', $group->carro);
        return $flag;
    }

    /**
     * A pass for a car that is present but not classified. It does not
     * count, whoever typed it; only a reviewer can say "contar mesmo assim".
     */
    public function unclassifiedCar(PassGroup $group): Flag
    {
        $flag = $this->aboutGroup(Flag::CARRO_NAO_CLASSIFICADO, $group);
        $flag->fonteId = count($group->entries) === 1 ? $group->entries[0]->fonteId : null;
        $flag->motivo = sprintf('O carro %d está presente mas não está classificado; esta passagem não conta, a menos que o revisor mande contar.', $group->carro);
        return $flag;
    }

    /**
     * A pass at the line while the log of an area (the pit lane or the
     * fueling station) says the car is inside it. One of the two records is
     * wrong, and the engine does not guess which: there is NO suggestion.
     *
     * - Two or more counters agree on the pass: it is HELD (HoldRule), not
     *   counted. If the car did leave and nobody logged the exit, the
     *   reviewer records it ("marcar saída"), and the pass then counts.
     *   Otherwise the reviewer discards the pass.
     * - One counter only: it did not count anyway; the flag names the counter.
     *
     * @param string $area RaceTimeline::AREA_BOX or RaceTimeline::AREA_ABASTECIMENTO
     */
    public function insideAnArea(PassGroup $group, string $area): Flag
    {
        $isPitLane = $area === RaceTimeline::AREA_BOX;
        $flag = $this->aboutGroup($isPitLane ? Flag::NO_BOX : Flag::NO_ABASTECIMENTO, $group);
        $place = $isPitLane ? 'box' : 'abastecimento';
        $flag->motivo = sprintf('O carro %d foi registrado na linha por %s enquanto constava como dentro do %s.', $group->carro, $this->sourceNames($group), $place);
        if (count($group->realSourceIds()) === 1) {
            $flag->fonteId = $group->realSourceIds()[0];
            $flag->motivo .= ' Só uma contagem viu a passagem, e ela não está contada.';
        } else {
            $flag->motivo .= sprintf(' Por isso, pela regra de contagem padrão, a passagem fica retida, fora da contagem. Se o carro saiu e a saída não foi registrada, marque a saída do %s (a passagem passa a contar); se a passagem não aconteceu, descarte-a.', $place);
        }
        if ($group->intervaloDesdeAnteriorMs !== null) {
            $flag->motivo .= sprintf(
                ' Além disso, ela está a %d s da passagem anterior do carro (a volta mínima plausível para ele é de %d s).',
                (int) round($group->intervaloDesdeAnteriorMs / 1000),
                (int) round((int) $group->voltaMinimaMs / 1000)
            );
        }
        return $flag;
    }

    /**
     * Informational: the log of an area still had the car inside, the
     * counters agreed on this pass and on another one lap later, and the
     * engine assumed the exit nobody logged, one second before this pass.
     * Nothing is held and no count is in doubt; the reviewer is told, and
     * can discard the passes if the car never left.
     */
    public function assumedExit(PassGroup $group, string $area): Flag
    {
        $flag = new Flag(Flag::SAIDA_PRESUMIDA, [$group->carro], $group->entryIds(), (string) $group->anchorEntryId());
        $flag->tsMs = $group->tsMs;
        $place = $area === RaceTimeline::AREA_BOX ? 'box' : 'abastecimento';
        $flag->motivo = sprintf(
            'O carro %d constava como dentro do %s, mas as contagens (%s) o registraram na linha aqui e de novo uma volta depois. O sistema presumiu que a saída do %s não foi registrada: considerou o carro fora a partir de 1 s antes desta passagem e contou as passagens. Se o carro não saiu, descarte-as; se saiu, confirme para parar de avisar.',
            $group->carro,
            $place,
            $this->sourceNames($group),
            $place
        );
        return $flag;
    }

    /**
     * A pass the counters agree on, held by the hold rule: it comes sooner
     * after the car's previous official pass than the car could have lapped.
     */
    public function tooCloseToThePreviousPass(PassGroup $group): Flag
    {
        $flag = $this->aboutGroup(Flag::PASSAGEM_PROXIMA_DEMAIS, $group);
        $flag->motivo = sprintf(
            'A passagem do carro %d registrada por %s está a %d s da passagem anterior dele, e a volta mínima plausível para este carro é de %d s. %s Se o carro passou mesmo, conte esta passagem; se é a mesma passagem registrada duas vezes, descarte-a.',
            $group->carro,
            $this->sourceNames($group),
            (int) round((int) $group->intervaloDesdeAnteriorMs / 1000),
            (int) round((int) $group->voltaMinimaMs / 1000),
            $this->heldOrCounted($group)
        );
        return $flag;
    }

    /**
     * A group whose entries are too far apart to be taken as two counters
     * agreeing, and in which no two entries are close: held. The flag names
     * the entry furthest from the others when one stands out (three
     * entries); with two there is no telling which one is off.
     */
    public function spreadTooWide(PassGroup $group, int $allowedMs): Flag
    {
        $flag = $this->aboutGroup(Flag::DISPERSAO_ALTA, $group);
        $outlier = $this->entryFurthestFromTheOthers($group);
        $flag->fonteId = $outlier === null ? null : $outlier->fonteId;
        $flag->motivo = sprintf(
            'Os registros do carro %d neste momento (%s) estão espalhados por %d s, mais que os %d s aceitos entre contagens que viram a mesma passagem%s. %s Se for uma passagem só, conte-a; se não, anule os registros errados.',
            $group->carro,
            $this->sourceNames($group),
            (int) round($group->spreadMs() / 1000),
            (int) round($allowedMs / 1000),
            $outlier === null ? '' : sprintf('; o mais afastado é o de %s', $this->sourceName($outlier->fonteId)),
            $this->heldOrCounted($group)
        );
        return $flag;
    }

    /**
     * One sentence saying the group is held. It is worded the same in
     * every publish mode, like every reason: whether the mode in force
     * counts the group anyway ("todas") is in the flag's
     * `efeito_de_confirmar` and `contagem_em_duvida`, not in its text.
     */
    private function heldOrCounted(PassGroup $group): string
    {
        return 'Por isso, pela regra de contagem padrão, ela fica retida, fora da contagem.';
    }

    /** Of three or more counters' entries, the one furthest from the middle one; null when there are only two. */
    private function entryFurthestFromTheOthers(PassGroup $group): ?Entry
    {
        $entries = [];
        foreach ($group->entries as $entry) {
            if ($entry->fonteId !== Source::REVISOR_ID) {
                $entries[] = $entry;
            }
        }
        $count = count($entries);
        if ($count < 3) {
            return null;
        }
        $middleMs = $entries[intdiv($count, 2)]->tsMs;
        $first = $entries[0];
        $last = $entries[$count - 1];
        return $middleMs - $first->tsMs > $last->tsMs - $middleMs ? $first : $last;
    }

    /** The names of the real counters in a group, for a sentence: "Contagem 1 e Contagem 3". */
    public function sourceNames(PassGroup $group): string
    {
        $names = [];
        foreach ($group->realSourceIds() as $fonteId) {
            $names[] = $this->sourceName($fonteId);
        }
        if (count($names) <= 1) {
            return $names[0] ?? 'o revisor';
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' e ' . $last;
    }

    /**
     * The counters agree on the car but their entries lie on both sides of
     * the finish. Which side the pass counts on decides a lap: it is either
     * the last pass of the race or the one pass allowed after it. The
     * median settled that; a person should look.
     *
     * @return Flag|null null when every entry of the group is on the same side of the finish
     */
    public function acrossTheFinish(PassGroup $group, int $finishMs): ?Flag
    {
        // The entries of a group are in time order: first and last are its two ends.
        $entries = $group->entries;
        $lastEntryMs = $entries[count($entries) - 1]->tsMs;
        if ($group->primeiroTsMs > $finishMs || $lastEntryMs <= $finishMs) {
            return null;
        }
        $flag = $this->aboutGroup(Flag::NO_ENCERRAMENTO, $group);
        $flag->motivo = sprintf(
            'O carro %d foi registrado dos dois lados do encerramento (de %d s antes a %d s depois); a passagem foi considerada %s encerramento.',
            $group->carro,
            (int) round(($finishMs - $group->primeiroTsMs) / 1000),
            (int) round(($lastEntryMs - $finishMs) / 1000),
            $group->tsMs <= $finishMs ? 'antes do' : 'depois do'
        );
        return $flag;
    }

    /** A group one of whose entries has a time the phone estimated. It is matched and counted like any other. */
    public function estimatedTime(PassGroup $group): Flag
    {
        $estimated = [];
        foreach ($group->entries as $entry) {
            if ($entry->qualidadeTempo !== Entry::TEMPO_EXATO) {
                $estimated[] = $entry;
            }
        }
        $flag = $this->aboutGroup(Flag::TEMPO_ESTIMADO, $group);
        $flag->fonteId = count($estimated) === 1 ? $estimated[0]->fonteId : null;
        $flag->motivo = sprintf(
            'O horário desta passagem do carro %d em %s foi estimado pelo aparelho (digitada sem conexão, antes de a página ser recarregada); confira se ela está no lugar certo.',
            $group->carro,
            count($estimated) === 1 ? $this->sourceName($estimated[0]->fonteId) : 'mais de uma contagem'
        );
        return $flag;
    }

    /**
     * An entry whose time is unknown: the phone had nothing to anchor its
     * clock to. It is in no pass group and counts for nothing, and
     * confirming it only stops this warning: the time it carries is the
     * phone's own clock, which nobody can check. A reviewer who knows the
     * car did pass adds a pass at the time they know.
     */
    public function unknownTime(Entry $entry): Flag
    {
        $flag = new Flag(Flag::TEMPO_DESCONHECIDO, [$entry->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->motivo = sprintf(
            '%s registrou o carro %d sem horário confiável (o aparelho não tinha sincronizado); o registro não entra na contagem. Se o carro passou mesmo, adicione a passagem no horário certo; depois anule este registro.',
            $this->sourceName($entry->fonteId),
            $entry->carro
        );
        return $flag;
    }

    /** The name of a source for a sentence, or a neutral word when the source is not known. */
    public function sourceName(int $fonteId): string
    {
        return isset($this->sourcesById[$fonteId]) ? $this->sourcesById[$fonteId]->nome : 'uma contagem';
    }

    /**
     * A flag about every entry of a group at the group's pass time. Its id
     * is built from the smallest entry id, which stays the same when another
     * counter's entry arrives late, so the review screen can track the flag.
     */
    private function aboutGroup(string $tipo, PassGroup $group): Flag
    {
        $flag = new Flag($tipo, [$group->carro], $group->entryIds(), (string) $group->anchorEntryId());
        $flag->tsMs = $group->tsMs;
        return $flag;
    }
}
