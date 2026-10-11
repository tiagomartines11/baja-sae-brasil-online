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
 * knows how to word them. None of these has a suggestion: the engine can
 * point at the problem but cannot know which record is the wrong one.
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

    /** A pass at the line while the pit log says the car is in the pit lane. */
    public function inThePitLane(PassGroup $group): Flag
    {
        $flag = $this->aboutGroup(Flag::NO_BOX, $group);
        $flag->motivo = sprintf('O carro %d foi registrado na linha enquanto constava como dentro do box.', $group->carro);
        return $flag;
    }

    /** A pass at the line while the fueling log says the car is in the fueling station. */
    public function inTheFuelingStation(PassGroup $group): Flag
    {
        $flag = $this->aboutGroup(Flag::NO_ABASTECIMENTO, $group);
        $flag->motivo = sprintf('O carro %d foi registrado na linha enquanto constava como dentro do abastecimento.', $group->carro);
        return $flag;
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
     * clock to. It is in no pass group and counts for nothing. Confirming
     * it means accepting the time it carries.
     */
    public function unknownTime(Entry $entry): Flag
    {
        $flag = new Flag(Flag::TEMPO_DESCONHECIDO, [$entry->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->motivo = sprintf(
            '%s registrou o carro %d sem horário confiável (o aparelho não tinha sincronizado); a passagem não entra na contagem. Confirme para contá-la no horário mostrado, ou anule.',
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
