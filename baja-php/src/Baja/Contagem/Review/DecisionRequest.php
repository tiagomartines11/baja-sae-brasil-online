<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use InvalidArgumentException;

/**
 * The body of a POST to contagem_decidir.php, read and checked for SHAPE
 * only: is it a decision at all, are the numbers numbers, is everything
 * there that this kind of decision needs.
 *
 * Expected shape:
 *   {
 *     "decisao": {"tipo": "reatribuir", "passagem_id": 12, "equipe_numero": 41, "motivo": "..."},
 *     "passagem_ids": [12, 15],
 *     "assinatura": "…sha1…",
 *     "ultima_decisao_id": 9
 *   }
 *
 * Nothing here looks at the race, so a request that makes no sense is
 * refused before the count lock is taken and before a single row is read.
 * Whether the entry belongs to this prova, whether the car exists and so on
 * needs the race and is checked afterwards, under the lock, by
 * DecisionResolver.
 *
 * Besides the engine's own decision types there are two that only the
 * review screen knows. Each is stored as ordinary decisions, in one step:
 *
 *   descartar_passagem  void every entry of the pass group that
 *                       "passagem_id" is in (a pass that did not happen)
 *   encerrar_exclusao   give an open-ended source exclusion
 *                       ("desfaz_decisao_id") an end at "ts_fim_ms"
 */
final class DecisionRequest
{
    public const DESCARTAR_PASSAGEM = 'descartar_passagem';
    public const ENCERRAR_EXCLUSAO = 'encerrar_exclusao';

    private const MAX_WATCHED_ENTRIES = 500;
    private const MAX_REASON_LENGTH = 500;

    /** Which numbers each kind of decision must carry: key in the request => message when it is missing or not a number. */
    private const REQUIRED_NUMBERS = [
        Decision::ANULAR => ['passagem_id' => 'Passagem inválida.'],
        Decision::CONFIRMAR => ['passagem_id' => 'Passagem inválida.'],
        self::DESCARTAR_PASSAGEM => ['passagem_id' => 'Passagem inválida.'],
        Decision::REATRIBUIR => ['passagem_id' => 'Passagem inválida.', 'equipe_numero' => 'Número de carro inválido.'],
        Decision::ADICIONAR => ['equipe_numero' => 'Número de carro inválido.', 'ts_ms' => 'Horário inválido.'],
        Decision::EXCLUIR_FONTE => ['fonte_id' => 'Fonte inválida.', 'ts_ms' => 'Início do intervalo inválido.'],
        Decision::DESFAZER => ['desfaz_decisao_id' => 'Decisão a desfazer inválida.'],
        self::ENCERRAR_EXCLUSAO => ['desfaz_decisao_id' => 'Exclusão a encerrar inválida.', 'ts_fim_ms' => 'Fim do intervalo inválido.'],
    ];

    /** Kinds of decision that are about one entry on the reviewer's screen. */
    private const ABOUT_ONE_ENTRY = [Decision::ANULAR, Decision::CONFIRMAR, Decision::REATRIBUIR, self::DESCARTAR_PASSAGEM];

    public string $tipo;

    /** @var array<string, int> the numbers of the decision, by their key in the request */
    public array $numeros = [];

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
    public static function parse(array $request): self
    {
        $raw = $request['decisao'] ?? null;
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Pedido sem decisão.');
        }
        $tipo = $raw['tipo'] ?? null;
        if (!is_string($tipo) || !isset(self::REQUIRED_NUMBERS[$tipo])) {
            throw new InvalidArgumentException('Tipo de decisão desconhecido.');
        }

        $parsed = new self();
        $parsed->tipo = $tipo;
        foreach (self::REQUIRED_NUMBERS[$tipo] as $key => $message) {
            $parsed->numeros[$key] = self::wholeNumber($raw[$key] ?? null, $message, $key === 'passagem_id');
        }
        // The one number that may be left out: no end means "até segunda ordem".
        if ($tipo === Decision::EXCLUIR_FONTE && ($raw['ts_fim_ms'] ?? null) !== null) {
            $parsed->numeros['ts_fim_ms'] = self::wholeNumber($raw['ts_fim_ms'], 'Fim do intervalo inválido.', false);
            if ($parsed->numeros['ts_fim_ms'] <= $parsed->numeros['ts_ms']) {
                throw new InvalidArgumentException('O fim do intervalo deve ser depois do início.');
            }
        }

        $parsed->motivo = self::reason($raw['motivo'] ?? null);
        $parsed->passagemIds = self::watchedEntries($request['passagem_ids'] ?? []);

        $assinatura = $request['assinatura'] ?? null;
        $parsed->assinatura = is_string($assinatura) && $assinatura !== '' ? $assinatura : null;

        $ultima = $request['ultima_decisao_id'] ?? null;
        $parsed->ultimaDecisaoId = self::isWholeNumber($ultima) ? (int) $ultima : null;

        $watchedWithoutSignature = $parsed->passagemIds !== [] && $parsed->assinatura === null;
        if (($parsed->isAboutOneEntry() && $parsed->passagemIds === []) || $watchedWithoutSignature) {
            // Without these the server could not tell whether the entry
            // changed under the reviewer, which is the whole point.
            throw new InvalidArgumentException('Pedido sem as passagens observadas ou sem assinatura.');
        }
        if ($parsed->isAboutOneEntry() && !in_array($parsed->numeros['passagem_id'], $parsed->passagemIds, true)) {
            // The signature only covers the watched entries. A decision
            // about an entry outside that list would slip past the check
            // that it is still as the reviewer saw it.
            throw new InvalidArgumentException('A passagem decidida não está entre as passagens observadas.');
        }

        return $parsed;
    }

    public function isAboutOneEntry(): bool
    {
        return in_array($this->tipo, self::ABOUT_ONE_ENTRY, true);
    }

    public function number(string $key): ?int
    {
        return $this->numeros[$key] ?? null;
    }

    /**
     * A whole number above zero. An entry id may also be below zero: a pass
     * added by a reviewer is not a row of contagem_passagem, and on the
     * timeline it carries "minus the id of the decision that added it".
     *
     * @param mixed $value
     */
    private static function wholeNumber($value, string $message, bool $mayBeNegative): int
    {
        if (!self::isWholeNumber($value) || (int) $value === 0 || (!$mayBeNegative && (int) $value < 0)) {
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
