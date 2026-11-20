<?php

namespace Baja\Certificado\Requerimento;

/**
 * What one save of the staff form did, as Fechamento decided it.
 *
 * Only what the page needs to say afterwards, the same split Resposta makes
 * for the public form: the page prints from these fields and decides
 * nothing of its own.
 */
final class Salvamento
{
    /**
     * @param string             $erro          why nothing was saved; '' when it was
     * @param int                $respostas     how many closing emails were owed
     * @param array<int, string> $naoEnviados   the protocols whose email the relay refused
     * @param int                $fechadosJunto how many duplicates were closed with it
     */
    private function __construct(
        public readonly string $erro,
        public readonly int $respostas,
        public readonly array $naoEnviados,
        public readonly int $fechadosJunto
    ) {
    }

    public static function recusado(string $erro): self
    {
        return new self($erro, 0, [], 0);
    }

    /** @param array<int, string> $naoEnviados */
    public static function salvo(int $respostas, array $naoEnviados, int $fechadosJunto): self
    {
        return new self('', $respostas, $naoEnviados, $fechadosJunto);
    }

    public function salvou(): bool
    {
        return $this->erro === '';
    }

    /** How many closing emails the relay accepted. */
    public function enviados(): int
    {
        return $this->respostas - count($this->naoEnviados);
    }
}
