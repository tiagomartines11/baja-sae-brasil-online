<?php

namespace Baja\Certificado\Requerimento;

use Baja\Certificado\Insercao\Texto;
use Baja\Model\CertificadoRequerimento;
use Baja\Util\Turnstile;

/**
 * What POST /requerimento does, without the HTML around it.
 *
 * It used to live in certificado/requerimento.php, between the header and the
 * form. It moved here (BAJAAPP-89) so that the questions that bug was about —
 * how many rows, how many receipts, how many staff alerts does pressing the
 * button six times produce — can be answered by a test instead of by a
 * student in production. The page builds one of these with the real captcha
 * and mailer (padrao()); a test builds one with stand-ins it can count.
 *
 * The order of the steps is the point, so here it is in one place:
 *
 *   1. Limite::esperaSegundos()  a read. An address already over the limit
 *                                stops here and costs nothing else.
 *   2. the trap field            a bot gets the success page and no row.
 *   3. the captcha               a failure costs no slot.
 *   4. Formulario                a form with errors costs no slot either.
 *   5. Duplicata (a read)        the same email already has this report open:
 *                                show its protocol and stop. No slot taken.
 *   6. Limite::reservar()        the slot is taken here, atomically. This is
 *                                what actually holds when six requests arrive
 *                                together; step 1 only saves work.
 *   7. Gravador::registrar()     the duplicate check and the write, together.
 *   8. the receipt and the alert, which are slow (two SMTP conversations) and
 *                                so come last, after everything that decides
 *                                whether they should be sent at all.
 */
final class Recepcao
{
    /**
     * @param \Closure(string, string): bool             $verificarCaptcha token, address
     * @param \Closure(CertificadoRequerimento): bool    $enviarRecibo
     * @param \Closure(CertificadoRequerimento): bool    $alertarEquipe
     */
    public function __construct(
        private \Closure $verificarCaptcha,
        private \Closure $enviarRecibo,
        private \Closure $alertarEquipe
    ) {
    }

    /** The real thing: Turnstile, and both messages over SMTP. */
    public static function padrao(): self
    {
        return new self(
            static fn (string $token, string $origem): bool => Turnstile::verificar($token, $origem),
            static fn (CertificadoRequerimento $r): bool => Aviso::confirmarAoParticipante($r),
            static fn (CertificadoRequerimento $r): bool => Aviso::alertarEquipe($r, Aviso::captchaAtivo())
        );
    }

    /**
     * Handle one submission.
     *
     * @param array<string, mixed>  $post    the raw request values
     * @param string                $origem  the caller's address
     * @param array<string, string> $eventos code => name, the list the form offered
     */
    public function receber(array $post, string $origem, array $eventos): Resposta
    {
        // Cleaned exactly as Formulario will clean it, or a trailing space buys
        // a fresh counter — the check and the count have to agree on what one
        // address is.
        $emailBruto = Texto::limpar(Texto::escalar($post['email'] ?? ''));

        $espera = Limite::esperaSegundos($origem, $emailBruto);

        if ($espera !== null) {
            return Resposta::espera($espera);
        }

        /*
         * The trap field. Anything in it means the submission was not typed by
         * somebody looking at the page, and the answer is the ordinary success
         * page with nothing written down — a bot told it failed tries again.
         */
        if (Texto::escalar($post[Formulario::CAMPO_ARMADILHA] ?? '') !== '') {
            return Resposta::armadilha();
        }

        if (!($this->verificarCaptcha)(Texto::escalar($post[Turnstile::CAMPO] ?? ''), $origem)) {
            return Resposta::captchaRecusado();
        }

        $formulario = new Formulario($post, $eventos);

        if (!$formulario->valido()) {
            return Resposta::formularioComErros($formulario);
        }

        /*
         * Has this same email already sent this same report, and is it still
         * open? Then this is somebody pressing the button again. Show them
         * the protocol they already have, and stop here: no row, no email,
         * and no slot taken from the limit below. Without this check, the
         * fourth press would be told "Muitos envios" instead of the protocol.
         *
         * This is only a quick look, without the lock. Gravador::registrar()
         * looks again, with the lock held, and that second look is the one
         * that really decides. This one just answers the common case early.
         */
        $jaAberta = Duplicata::doMesmoEmail(Duplicata::abertas($formulario), $formulario->email());

        if ($jaAberta !== null) {
            return Resposta::registrada($formulario, new Registro(Registro::REPETIDO, $jaAberta), false);
        }

        $espera = Limite::reservar($origem, $formulario->email());

        if ($espera !== null) {
            return Resposta::espera($espera);
        }

        $registro = Gravador::registrar($formulario);

        if (!$registro->gravou()) {
            return Resposta::registrada($formulario, $registro, false);
        }

        $requerimento = $registro->requerimento;

        /*
         * The row is written before anything is sent, and the protocol is
         * shown whether or not either message goes out.
         *
         * This is why the report is stored rather than mailed. A server with
         * no relay configured still takes the report: it lands in the queue
         * with avisado_em null, the staff page says so in as many words, and
         * the person is told plainly that no confirmation is coming. Refusing
         * the submission instead would turn a mail misconfiguration into "this
         * person cannot report that their own certificate is wrong" — and the
         * form is now the only channel there is.
         *
         * The receipt goes out for every row written, including one this
         * system decides not to alert anybody about. Being the fourth person
         * to report one typo is not a reason to hear nothing back.
         */
        $reciboEnviado = ($this->enviarRecibo)($requerimento);

        if (!$registro->avisarEquipe()) {
            // Staff already know about this one. The row exists; only the
            // repeat alert is suppressed.
            Gravador::marcarAvisado($requerimento);
        } elseif (($this->alertarEquipe)($requerimento)) {
            Gravador::marcarAvisado($requerimento);
        } else {
            error_log('Certificado\Requerimento: saved ' . $requerimento->getRequerimentoId() . ' but the staff alert failed.');
        }

        return Resposta::registrada($formulario, $registro, $reciboEnviado);
    }
}
