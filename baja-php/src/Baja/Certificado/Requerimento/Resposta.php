<?php

namespace Baja\Certificado\Requerimento;

/**
 * What the page should show after a POST, as Recepcao decided it.
 *
 * Only what the template needs. The page prints from these fields and makes
 * no decisions of its own, which is what keeps every decision testable.
 */
final class Resposta
{
    private function __construct(
        /** Seconds to wait, when the caller is over the limit. */
        public readonly ?int $espera = null,
        /** The submission, for re-rendering the form or reading its values. */
        public readonly ?Formulario $formulario = null,
        /**
         * The protocol to show. Null: show the form again. Empty string: the
         * trap was filled, show the success page with no number.
         */
        public readonly ?string $protocolo = null,
        /** A refusal that is not about any one field. */
        public readonly string $avisoErro = '',
        /** Whether the receipt was accepted by the relay. */
        public readonly bool $reciboEnviado = false,
        /**
         * The same person sent this report already and it is still open, so
         * nothing new was written or sent. The page says so, because the
         * receipt they are expecting is the one they already got.
         */
        public readonly bool $jaAberto = false
    ) {
    }

    public static function espera(int $segundos): self
    {
        return new self(espera: $segundos);
    }

    public static function armadilha(): self
    {
        return new self(protocolo: '');
    }

    public static function captchaRecusado(): self
    {
        return new self(avisoErro: 'Não foi possível confirmar que você não é um robô. Recarregue a página e tente de novo.');
    }

    public static function formularioComErros(Formulario $formulario): self
    {
        return new self(formulario: $formulario);
    }

    public static function registrada(Formulario $formulario, Registro $registro, bool $reciboEnviado): self
    {
        return new self(
            formulario: $formulario,
            protocolo: $registro->protocolo(),
            reciboEnviado: $reciboEnviado,
            jaAberto: $registro->situacao === Registro::REPETIDO
        );
    }
}
