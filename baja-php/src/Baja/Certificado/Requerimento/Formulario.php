<?php

namespace Baja\Certificado\Requerimento;

use Baja\Certificado\Certificado;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Nome;
use Baja\Certificado\Token;
use Baja\Util\Mailer;

/**
 * One submission of /requerimento, checked.
 *
 * Holds the values as typed, the values as they will be stored, and what is
 * wrong with them. The page re-renders from the first of those so nobody
 * retypes a form because of one bad field, stores the second, and prints the
 * third next to the field it belongs to.
 *
 * What this does not do is decide whether the report is true. Every rule here
 * is about whether a person can act on the row — is there a certificate to
 * look at, is there an address to reply to, is there a sentence saying what
 * is wrong. Judging the claim is the staff page's job, and it is a judgement
 * about a person that no validator should be making.
 *
 * Nothing is checked against `participantes` beyond the token resolving.
 * Answering "no certificate matches that CPF and name" here would rebuild the
 * oracle /buscar exists to close — and the person reporting a missing
 * certificate has already been told that nothing matched.
 */
final class Formulario
{
    /**
     * A field no person sees and no person fills in.
     *
     * Named like a real one, because a bot that reads labels skips
     * `honeypot`. Anything in it means the submission was not typed by
     * somebody looking at the page.
     */
    public const CAMPO_ARMADILHA = 'sobrenome_meio';

    private const DESCRICAO_MINIMA = 15;
    private const DESCRICAO_MAXIMA = 2000;
    private const NOME_MAXIMO      = 300;
    private const DOCUMENTO_MAXIMO = 32;
    private const TELEFONE_MAXIMO  = 40;
    private const EVENTO_MAXIMO    = 160;

    /** The value the event select carries when the event is not on the list. */
    public const EVENTO_OUTRO = 'outro';

    /** @var array<string, string> field => message */
    private array $erros = [];

    /** @var array<string, string> the values as typed, for re-rendering */
    private array $bruto;

    private ?Certificado $certificado = null;

    /**
     * @param array<string, mixed>  $post    the raw request values
     * @param array<string, string> $eventos code => name, the list the form offered
     */
    public function __construct(array $post, private array $eventos)
    {
        $this->bruto = [
            'caso'         => Texto::escalar($post['caso'] ?? ''),
            'token'        => Texto::escalar($post['token'] ?? ''),
            'evento'       => Texto::escalar($post['evento'] ?? ''),
            'evento_texto' => Texto::limpar(Texto::escalar($post['evento_texto'] ?? '')),
            'documento'    => Texto::limpar(Texto::escalar($post['documento'] ?? '')),
            'nome'         => Texto::normalizarEspacos(Texto::limpar(Texto::escalar($post['nome'] ?? ''))),
            'email'        => Texto::limpar(Texto::escalar($post['email'] ?? '')),
            'telefone'     => Texto::limpar(Texto::escalar($post['telefone'] ?? '')),
            'descricao'    => Texto::limpar(Texto::escalar($post['descricao'] ?? '')),
            'ciente'       => Texto::escalar($post['ciente'] ?? ''),
        ];

        $this->validar();
    }

    public function valido(): bool
    {
        return $this->erros === [];
    }

    /** @return array<string, string> */
    public function erros(): array
    {
        return $this->erros;
    }

    public function erro(string $campo): string
    {
        return $this->erros[$campo] ?? '';
    }

    public function valor(string $campo): string
    {
        return $this->bruto[$campo] ?? '';
    }

    public function caso(): string
    {
        return $this->bruto['caso'];
    }

    /** The certificate being reported, resolved, or null for a missing one. */
    public function certificado(): ?Certificado
    {
        return $this->certificado;
    }

    public function token(): ?string
    {
        return $this->certificado?->getToken();
    }

    /**
     * The event code to store, or null.
     *
     * For a case that carries a token it comes from the certificate, not from
     * the form: the certificate already says which event it is for, and a
     * second answer to that question is a second answer to get wrong.
     */
    public function eventoCodigo(): ?string
    {
        if ($this->certificado !== null) {
            return (string) $this->certificado->getEvento()->getEventoId();
        }

        $escolhido = $this->bruto['evento'];

        return isset($this->eventos[$escolhido]) ? $escolhido : null;
    }

    /** What they typed when the event was not on the list, or null. */
    public function eventoTexto(): ?string
    {
        if ($this->certificado !== null || $this->bruto['evento'] !== self::EVENTO_OUTRO) {
            return null;
        }

        return $this->bruto['evento_texto'] !== '' ? $this->bruto['evento_texto'] : null;
    }

    public function documento(): string
    {
        return $this->bruto['documento'];
    }

    public function nome(): string
    {
        return $this->bruto['nome'];
    }

    public function email(): string
    {
        return $this->bruto['email'];
    }

    public function telefone(): ?string
    {
        return $this->bruto['telefone'] !== '' ? $this->bruto['telefone'] : null;
    }

    public function descricao(): string
    {
        return $this->bruto['descricao'];
    }

    private function validar(): void
    {
        $this->validarCaso();
        $this->validarCertificado();
        $this->validarEvento();
        $this->validarDocumento();
        $this->validarNome();
        $this->validarEmail();
        $this->validarTelefone();
        $this->validarDescricao();
        $this->validarCiente();
    }

    private function validarCaso(): void
    {
        if (!Caso::valido($this->bruto['caso'])) {
            $this->erros['caso'] = 'Escolha o que está acontecendo.';
        }
    }

    private function validarCertificado(): void
    {
        $caso  = $this->bruto['caso'];
        $token = $this->bruto['token'];

        if (!Caso::valido($caso) || !Caso::exigeToken($caso)) {
            return;
        }

        if ($token === '' || !Token::isWellFormed($token)) {
            $this->erros['token'] = 'Comece pela busca e use um dos links de correção '
                                  . 'que aparecem no certificado em questão.';

            return;
        }

        $this->certificado = Certificado::fromToken($token);

        if ($this->certificado === null) {
            // Same wording as the missing-token case. To the person they are
            // one problem — the page cannot see the certificate they mean —
            // and telling a caller that a token they invented is not a
            // certificate is an answer /verificar already declines to give.
            $this->erros['token'] = 'Não foi possível identificar esse certificado. '
                                  . 'Refaça a busca e use um dos links de correção que '
                                  . 'aparecem nele.';
        }
    }

    private function validarEvento(): void
    {
        $caso = $this->bruto['caso'];

        if (!Caso::valido($caso) || !Caso::exigeEvento($caso)) {
            return;
        }

        $escolhido = $this->bruto['evento'];

        if ($escolhido === self::EVENTO_OUTRO) {
            if ($this->bruto['evento_texto'] === '') {
                $this->erros['evento'] = 'Diga qual foi o evento e em que ano.';
            } elseif (mb_strlen($this->bruto['evento_texto'], 'UTF-8') > self::EVENTO_MAXIMO) {
                $this->erros['evento'] = 'Descreva o evento em até ' . self::EVENTO_MAXIMO . ' caracteres.';
            }

            return;
        }

        if (!isset($this->eventos[$escolhido])) {
            $this->erros['evento'] = 'Escolha o evento, ou "Outro / não está na lista".';
        }
    }

    private function validarDocumento(): void
    {
        $documento = $this->bruto['documento'];

        if ($documento === '') {
            $this->erros['documento'] = 'Informe o CPF ou o passaporte.';

            return;
        }

        if (mb_strlen($documento, 'UTF-8') > self::DOCUMENTO_MAXIMO) {
            $this->erros['documento'] = 'Documento longo demais.';

            return;
        }

        // Check digits are not verified, for the reason Documento::isValidCpf
        // records: a rule that rejects an invalid CPF turns away every
        // foreign participant and everybody whose CPF was mistyped at
        // registration — on this form of all forms, exactly the population it
        // exists to serve.
        if (preg_match('/\A[\p{L}\p{N}.\-\/ ]+\z/u', $documento) !== 1) {
            $this->erros['documento'] = 'Use apenas letras, números, pontos e traços.';
        }
    }

    private function validarNome(): void
    {
        $nome = $this->bruto['nome'];

        if ($nome === '') {
            $this->erros['nome'] = 'Informe o nome completo.';

            return;
        }

        if (mb_strlen($nome, 'UTF-8') > self::NOME_MAXIMO) {
            $this->erros['nome'] = 'Nome longo demais.';

            return;
        }

        // Two parts, using the same tokenizer /buscar matches names with, so
        // "completo" means the same thing on both forms. It drops Portuguese
        // connectives, so "Ana de Souza" counts as two and "Ana de" does not.
        if (count(Nome::parts($nome)) < 2) {
            $this->erros['nome'] = 'Informe o nome completo, como consta no seu documento.';
        }
    }

    private function validarEmail(): void
    {
        $email = $this->bruto['email'];

        if ($email === '') {
            $this->erros['email'] = 'Informe um e-mail para retorno.';

            return;
        }

        if (!Mailer::enderecoValido($email)) {
            $this->erros['email'] = 'Esse e-mail não parece válido.';
        }
    }

    private function validarTelefone(): void
    {
        $telefone = $this->bruto['telefone'];

        if ($telefone === '') {
            return;
        }

        if (mb_strlen($telefone, 'UTF-8') > self::TELEFONE_MAXIMO) {
            $this->erros['telefone'] = 'Telefone longo demais.';

            return;
        }

        if (preg_match('/\A[\p{N}+()\-. ]+\z/u', $telefone) !== 1) {
            $this->erros['telefone'] = 'Use apenas números, espaços, parênteses, + e -.';
        }
    }

    private function validarDescricao(): void
    {
        $descricao = $this->bruto['descricao'];

        if ($descricao === '') {
            $this->erros['descricao'] = 'Descreva o problema.';

            return;
        }

        if (mb_strlen($descricao, 'UTF-8') < self::DESCRICAO_MINIMA) {
            $this->erros['descricao'] = 'Escreva um pouco mais, dizendo o que está errado e qual seria o correto.';

            return;
        }

        if (mb_strlen($descricao, 'UTF-8') > self::DESCRICAO_MAXIMA) {
            $this->erros['descricao'] = 'Descreva em até ' . self::DESCRICAO_MAXIMA . ' caracteres.';
        }
    }

    private function validarCiente(): void
    {
        if ($this->bruto['ciente'] !== '1') {
            $this->erros['ciente'] = 'É preciso confirmar que leu o aviso acima.';
        }
    }
}
