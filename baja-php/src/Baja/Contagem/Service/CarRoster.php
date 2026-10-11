<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Settings;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Which cars take part in a lap-counted prova.
 *
 * A car takes part when its team is PRESENT at the event and CLASSIFIED for
 * this prova. Classification is one of the prova's own input fields, named
 * by the setting `campo_classificacao` (for example "APTO", an enum SIM/NÃO
 * filled per car in the normal dashboard, like DESCLAS). It is "SIM" or it
 * is not: a blank field means not classified, so that a car nobody has
 * looked at cannot slip into a race.
 *
 * With no field configured every present car is classified, which is how
 * the prova behaved before this setting existed.
 *
 * This list is used everywhere a car list is: the tiles and warnings of the
 * count pages, the pit log, the review table, the public feed, the engine.
 */
final class CarRoster
{
    /** What the classification field must hold for a car to take part. */
    public const CLASSIFIED_VALUE = 'SIM';

    private ?string $field;

    /** @var array<int, array{numero: int, nome: string, escola: string}> */
    private array $classified = [];

    /** @var array<int, array{numero: int, nome: string, escola: string}> */
    private array $unclassified = [];

    public static function forProva(Prova $prova): self
    {
        $settings = Settings::fromArray($prova->getParams()->contagem ?? null);
        $roster = new self($settings->campoClassificacao);

        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT e.equipe_id, e.equipe, e.escola, i.dados
               FROM equipe e
          LEFT JOIN input i ON i.evento_id = e.evento_id AND i.equipe_id = e.equipe_id AND i.prova_id = :prova
              WHERE e.evento_id = :evento AND e.presente = 1
           ORDER BY e.equipe_id'
        );
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $car = [
                'numero' => (int) $row['equipe_id'],
                'nome' => (string) $row['equipe'],
                'escola' => (string) $row['escola'],
            ];
            $dados = (array) json_decode((string) ($row['dados'] ?? ''), true);
            if ($roster->isClassifiedBy($dados)) {
                $roster->classified[$car['numero']] = $car;
            } else {
                $roster->unclassified[$car['numero']] = $car;
            }
        }
        return $roster;
    }

    private function __construct(?string $field)
    {
        $this->field = $field !== null && $field !== '' ? $field : null;
    }

    /** The name of the classification field, or null when the prova has none. */
    public function classificationField(): ?string
    {
        return $this->field;
    }

    /**
     * Present and classified: the cars of the race.
     *
     * @return array<int, array{numero: int, nome: string, escola: string}> keyed by car number
     */
    public function classified(): array
    {
        return $this->classified;
    }

    /**
     * Present but not classified. A pass typed for one of these is stored
     * and shown to a reviewer, and does not count.
     *
     * @return array<int, array{numero: int, nome: string, escola: string}> keyed by car number
     */
    public function unclassified(): array
    {
        return $this->unclassified;
    }

    /** @param array<string, mixed> $dados the car's score row for this prova (empty when it has none) */
    private function isClassifiedBy(array $dados): bool
    {
        if ($this->field === null) {
            return true;
        }
        $value = $dados[$this->field] ?? null;
        return is_string($value) && mb_strtoupper(trim($value)) === self::CLASSIFIED_VALUE;
    }
}
