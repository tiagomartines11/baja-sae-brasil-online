<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Source;
use InvalidArgumentException;

/**
 * The body of a POST to contagem_decidir.php, checked and turned into a
 * Decision the service can store.
 *
 * Expected shape:
 *   {
 *     "decisao": {"tipo": "reatribuir", "passagem_id": 12, "equipe_numero": 41, "motivo": "..."},
 *     "passagem_ids": [12, 15],
 *     "assinatura": "…sha1…",
 *     "ultima_decisao_id": 9
 *   }
 *
 * Everything here comes from a browser, so nothing is trusted: an entry id
 * must belong to this prova, a car must be one of the cars at the event.
 *
 * ENTRY IDS BELOW ZERO. A pass added by a reviewer is not a row of
 * contagem_passagem; on the timeline it carries the id "minus the id of the
 * decision that added it". Voiding such a pass therefore means undoing that
 * decision, and that is what a request to void entry -N is turned into.
 */
final class DecisionRequest
{
    private const MAX_WATCHED_ENTRIES = 500;
    private const MAX_REASON_LENGTH = 500;

    public Decision $decision;
    public ?string $motivo = null;

    /** @var int[] the entries the reviewer was looking at */
    public array $passagemIds = [];

    /** Signature of those entries as the reviewer's screen had it. */
    public ?string $assinatura = null;

    /** Highest decision id the reviewer's screen knew about. */
    public ?int $ultimaDecisaoId = null;

    /**
     * @param array<string, mixed> $request the decoded JSON body
     * @throws InvalidArgumentException with a message in Portuguese, safe to show
     */
    public static function parse(array $request, EngineInput $input): self
    {
        $raw = $request['decisao'] ?? null;
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Pedido sem decisão.');
        }
        $tipo = $raw['tipo'] ?? null;
        if (!is_string($tipo) || !in_array($tipo, Decision::TIPOS, true)) {
            throw new InvalidArgumentException('Tipo de decisão desconhecido.');
        }

        $parsed = new self();
        $parsed->decision = self::buildDecision($tipo, $raw, $input);
        $parsed->motivo = self::reason($raw['motivo'] ?? null);
        $parsed->passagemIds = self::watchedEntries($request['passagem_ids'] ?? []);

        $assinatura = $request['assinatura'] ?? null;
        $parsed->assinatura = is_string($assinatura) && $assinatura !== '' ? $assinatura : null;

        $ultima = $request['ultima_decisao_id'] ?? null;
        $parsed->ultimaDecisaoId = self::isWholeNumber($ultima) ? (int) $ultima : null;

        $aboutOneEntry = in_array($tipo, ActiveDecisions::ENTRY_TYPES, true);
        $watchedWithoutSignature = $parsed->passagemIds !== [] && $parsed->assinatura === null;
        if (($aboutOneEntry && $parsed->passagemIds === []) || $watchedWithoutSignature) {
            // Without these the server could not tell whether the entry
            // changed under the reviewer, which is the whole point.
            throw new InvalidArgumentException('Pedido sem as passagens observadas ou sem assinatura.');
        }
        if ($aboutOneEntry && !in_array((int) $raw['passagem_id'], $parsed->passagemIds, true)) {
            // The signature only covers the watched entries. A decision
            // about an entry outside that list would slip past the check
            // that it is still as the reviewer saw it.
            throw new InvalidArgumentException('A passagem decidida não está entre as passagens observadas.');
        }

        return $parsed;
    }

    /** @param array<string, mixed> $raw */
    private static function buildDecision(string $tipo, array $raw, EngineInput $input): Decision
    {
        // Id 0: the real id is assigned by the database when it is stored.
        switch ($tipo) {
            case Decision::ANULAR:
                $entryId = self::entryId($raw, $input, true);
                // Voiding an added pass is undoing the decision that added it.
                return $entryId < 0 ? Decision::desfazer(0, -$entryId) : Decision::anular(0, $entryId);
            case Decision::CONFIRMAR:
                return Decision::confirmar(0, self::entryId($raw, $input, true));
            case Decision::REATRIBUIR:
                return Decision::reatribuir(0, self::entryId($raw, $input, false), self::validCar($raw, $input));
            case Decision::ADICIONAR:
                return Decision::adicionar(0, self::validCar($raw, $input), self::positiveInt($raw, 'ts_ms', 'Horário inválido.'));
            case Decision::EXCLUIR_FONTE:
                return self::buildSourceExclusion($raw, $input);
            default:
                return self::buildUndo($raw, $input);
        }
    }

    /** @param array<string, mixed> $raw */
    private static function buildSourceExclusion(array $raw, EngineInput $input): Decision
    {
        $fonteId = self::positiveInt($raw, 'fonte_id', 'Fonte inválida.');
        $known = false;
        foreach ($input->sources as $source) {
            if ($source->id === $fonteId
                && in_array($source->tipo, [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true)) {
                $known = true;
            }
        }
        if (!$known) {
            throw new InvalidArgumentException('Esta fonte não pertence a esta prova.');
        }
        $from = self::positiveInt($raw, 'ts_ms', 'Início do intervalo inválido.');
        // No end means "até segunda ordem": the counter's device is gone and
        // nobody knows yet when, or whether, the source comes back.
        $until = ($raw['ts_fim_ms'] ?? null) === null
            ? null
            : self::positiveInt($raw, 'ts_fim_ms', 'Fim do intervalo inválido.');
        if ($until !== null && $until <= $from) {
            throw new InvalidArgumentException('O fim do intervalo deve ser depois do início.');
        }
        return Decision::excluirFonte(0, $fonteId, $from, $until);
    }

    /** @param array<string, mixed> $raw */
    private static function buildUndo(array $raw, EngineInput $input): Decision
    {
        $targetId = self::positiveInt($raw, 'desfaz_decisao_id', 'Decisão a desfazer inválida.');
        $target = ActiveDecisions::indexById($input->decisions)[$targetId] ?? null;
        if ($target === null) {
            throw new InvalidArgumentException('Esta decisão não pertence a esta prova.');
        }
        if ($target->tipo === Decision::DESFAZER) {
            // Undoing an undo would leave a chain nobody can read in the
            // history. Making the original decision again says the same thing.
            throw new InvalidArgumentException('Não é possível desfazer um "desfazer". Registre a decisão novamente.');
        }
        return Decision::desfazer(0, $targetId);
    }

    /**
     * The entry a decision is about: a stored entry of this prova, or (when
     * allowed) a pass a reviewer added, named by its id below zero.
     *
     * @param array<string, mixed> $raw
     */
    private static function entryId(array $raw, EngineInput $input, bool $allowAddedPass): int
    {
        $value = $raw['passagem_id'] ?? null;
        if (!self::isWholeNumber($value) || (int) $value === 0) {
            throw new InvalidArgumentException('Passagem inválida.');
        }
        $id = (int) $value;

        if ($id < 0) {
            if (!$allowAddedPass) {
                throw new InvalidArgumentException('Uma passagem adicionada pelo revisor não pode ser trocada de carro: remova-a e adicione no carro certo.');
            }
            $addition = ActiveDecisions::filter($input->decisions)[-$id] ?? null;
            if ($addition === null || $addition->tipo !== Decision::ADICIONAR) {
                throw new InvalidArgumentException('Esta passagem adicionada não existe mais.');
            }
            return $id;
        }

        foreach ($input->entries as $entry) {
            if ($entry->id === $id) {
                return $id;
            }
        }
        throw new InvalidArgumentException('Esta passagem não pertence a esta prova.');
    }

    /** @param array<string, mixed> $raw */
    private static function validCar(array $raw, EngineInput $input): int
    {
        $car = self::positiveInt($raw, 'equipe_numero', 'Número de carro inválido.');
        if (!in_array($car, $input->validCars, true)) {
            throw new InvalidArgumentException('O carro ' . $car . ' não está presente neste evento.');
        }
        return $car;
    }

    /** @param array<string, mixed> $raw */
    private static function positiveInt(array $raw, string $key, string $message): int
    {
        $value = $raw[$key] ?? null;
        if (!self::isWholeNumber($value) || (int) $value <= 0) {
            throw new InvalidArgumentException($message);
        }
        return (int) $value;
    }

    /** @param mixed $value */
    private static function isWholeNumber($value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1);
    }

    /** @param mixed $value */
    private static function reason($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, self::MAX_REASON_LENGTH);
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private static function watchedEntries($value): array
    {
        if (!is_array($value) || count($value) > self::MAX_WATCHED_ENTRIES) {
            throw new InvalidArgumentException('Lista de passagens observadas inválida.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!self::isWholeNumber($id)) {
                throw new InvalidArgumentException('Lista de passagens observadas inválida.');
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }
}
