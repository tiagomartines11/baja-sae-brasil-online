<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use RuntimeException;

/**
 * A decision that made sense on the reviewer's screen and no longer does:
 * somebody decided first, or the entries changed. Answered with HTTP 409 and
 * the fresh state; nothing is stored.
 */
final class DecisionConflict extends RuntimeException
{
    /** @var array{erro: string, motivo: string, por: ?string, mensagem: string} the body of the 409 answer, without the state */
    public array $body;

    /** @param array{erro: string, motivo: string, por: ?string, mensagem: string} $body */
    private function __construct(array $body)
    {
        parent::__construct($body['mensagem']);
        $this->body = $body;
    }

    public static function alreadyDecided(?string $username): self
    {
        return new self([
            'erro' => 'conflito',
            'motivo' => DecisionValidator::MOTIVO_JA_DECIDIDO,
            'por' => $username,
            'mensagem' => 'Já decidido por ' . ($username ?? 'outra pessoa') . '.',
        ]);
    }

    public static function dataChanged(): self
    {
        return new self([
            'erro' => 'conflito',
            'motivo' => DecisionValidator::MOTIVO_DADOS_MUDARAM,
            'por' => null,
            'mensagem' => 'Os dados mudaram desde que você abriu este item. Confira de novo antes de decidir.',
        ]);
    }
}
