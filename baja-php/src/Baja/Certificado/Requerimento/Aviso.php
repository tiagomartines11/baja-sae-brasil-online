<?php

namespace Baja\Certificado\Requerimento;

use Baja\Certificado\Config;
use Baja\Certificado\Documento;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\EventoQuery;
use Baja\Url;
use Baja\Util\Mailer;
use Baja\Util\Turnstile;

/**
 * The two messages a report sends.
 *
 * Neither carries the report. That is the point of storing it: the row lives
 * in `certificado_requerimento` behind the `certificados` permission, where it has
 * a retention policy, an access decision, and a record of who acted on it.
 * An email has none of those — it is copied to every mailbox it reaches, kept
 * for as long as each of those mailboxes keeps anything, and forwarded by
 * hand. Putting a CPF and a full legal name into one, to a distribution list,
 * would undo in a single message what removing the CPF from certificate URLs
 * was for.
 *
 * So the staff message is a pointer: what kind of report, which event, and a
 * link. Everything identifying is one click away, on a page that already
 * knows who is reading it.
 *
 * The participant's copy is a receipt. It repeats nothing they did not type,
 * carries no name, and quotes none of their description back at them —
 * because the address in the form is unverified, and a form that emails
 * attacker-supplied text to an arbitrary address is a spam relay with SAE
 * BRASIL on the return path.
 */
final class Aviso
{
    /**
     * Tell the people who can act on it that a report arrived.
     *
     * Separate from the participant's receipt, and called separately, because
     * the two are suppressed under different conditions: a fourth report about
     * one certificate on one afternoon does not need a fourth alert, but the
     * fourth person still gets told their report was received. Folding them
     * into one call is how the person who reported last gets nothing.
     *
     * @return bool whether the relay accepted it. False means the report is in
     *              the queue with nobody told about it, which is what
     *              `avisado_em` staying null records.
     */
    public static function alertarEquipe(CertificadoRequerimento $requerimento, bool $captchaVerificado): bool
    {
        // Before resolving recipients, not after. Working out who holds the
        // permission costs a scan of `user` and a query against the forum
        // database, and with no relay configured the answer is thrown away —
        // on a page a participant is waiting on. It also keeps the log honest:
        // "nobody to notify" should mean nobody holds the permission, not
        // that SMTP is unset.
        if (!Mailer::configurado()) {
            return false;
        }

        $destinatarios = Destinatarios::todos();

        if ($destinatarios === []) {
            return false;
        }

        $linhas = [
            'Um participante abriu um requerimento sobre certificados.',
            '',
            'Tipo:      ' . Caso::resumo((string) $requerimento->getCaso()),
            'Evento:    ' . self::descreverEvento($requerimento),
            'Protocolo: ' . $requerimento->getRequerimentoId(),
            'Recebido:  ' . $requerimento->getCriadoEm('d/m/Y H:i'),
        ];

        if (!$captchaVerificado) {
            // Not a judgement about the report, and staff should not treat it
            // as one. It says the deployment has no Turnstile keys — which is
            // a configuration problem, and this line is the only place it is
            // visible without reading a container log.
            $linhas[] = '';
            $linhas[] = 'ATENÇÃO: este envio não passou por verificação anti-robô '
                      . '(Turnstile não está configurado neste servidor).';
        }

        $linhas[] = '';
        $linhas[] = 'Os dados do requerimento (nome, documento, contato e a descrição do';
        $linhas[] = 'problema) não estão neste e-mail. Eles ficam na página abaixo, que exige';
        $linhas[] = 'a permissão "certificados":';
        $linhas[] = '';
        $linhas[] = self::urlDaEquipe($requerimento);
        $linhas[] = '';
        $linhas[] = 'Nada foi alterado em nenhum certificado. Um requerimento é um pedido de análise.';

        return Mailer::enviar(
            $destinatarios,
            sprintf('[Certificados] %s / %s', Caso::resumo((string) $requerimento->getCaso()), $requerimento->getRequerimentoId()),
            implode("\n", $linhas) . "\n"
        );
    }

    /**
     * The participant's receipt.
     *
     * Sent on every accepted submission, including one this system decides not
     * to alert anybody about. The person has no other channel — the form is
     * the whole of it — so this message is also where they are told what to
     * reply to, and the reply address is whatever SMTP_FROM_EMAIL is. That
     * mailbox has to be read by somebody.
     *
     * @return bool whether the relay accepted it, so the page can say plainly
     *              that no confirmation is coming rather than promising one.
     */
    public static function confirmarAoParticipante(CertificadoRequerimento $requerimento): bool
    {
        $linhas = [
            'Recebemos o seu requerimento sobre certificados SAE BRASIL.',
            '',
            'Protocolo: ' . $requerimento->getRequerimentoId(),
            'Tipo:      ' . Caso::resumo((string) $requerimento->getCaso()),
            'Evento:    ' . self::descreverEvento($requerimento),
            'Documento: ' . Documento::mascarar((string) $requerimento->getDocumento()),
            'Recebido:  ' . $requerimento->getCriadoEm('d/m/Y H:i'),
            '',
            'O que acontece agora: a equipe responsável pelos certificados vai analisar',
            'o pedido e responder por este e-mail. Guarde o número de protocolo acima.',
            '',
            'Enquanto isso, nada muda: certificados já emitidos continuam válidos e',
            'verificáveis.',
            '',
            '--',
            'Sobre os seus dados',
            '',
            'Os dados que você enviou são usados apenas para analisar e responder a este',
            'pedido, e ficam acessíveis à equipe da SAE BRASIL responsável por certificados.',
            'Para pedir acesso, correção ou exclusão dos seus dados de contato, responda a',
            'este e-mail citando o protocolo.',
            '',
            'Se você não fez este pedido, responda avisando. Alguém pode ter informado o',
            'seu endereço por engano.',
        ];

        if (Config::PRIVACY_NOTICE_URL !== '') {
            $linhas[] = '';
            $linhas[] = 'Aviso de Privacidade: ' . Config::PRIVACY_NOTICE_URL;
        }

        return Mailer::enviar(
            [(string) $requerimento->getEmail()],
            'Recebemos o seu requerimento sobre certificados, protocolo ' . $requerimento->getRequerimentoId(),
            implode("\n", $linhas) . "\n"
        );
    }

    /**
     * The event in words, whichever of the two columns holds it.
     *
     * "não informado" is a real answer here: a report that a certificate is
     * missing may name an event this system has never heard of, which is
     * exactly the case the free-text field exists for.
     */
    private static function descreverEvento(CertificadoRequerimento $requerimento): string
    {
        $codigo = $requerimento->getEventoId();

        if ($codigo !== null && $codigo !== '') {
            $evento = EventoQuery::create()->findPk($codigo);

            if ($evento !== null) {
                return html_entity_decode(
                    (string) ($evento->getTitulo() ?: $evento->getNome()),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }

            return $codigo;
        }

        $texto = (string) $requerimento->getEventoTexto();

        return $texto !== '' ? $texto . ' (informado pelo participante)' : 'não informado';
    }

    public static function urlDaEquipe(CertificadoRequerimento $requerimento): string
    {
        return Url::subdomain('juiz', '/certificados_requerimentos.php?id=' . urlencode((string) $requerimento->getRequerimentoId()));
    }

    /** Whether the anti-robot check is actually running. */
    public static function captchaAtivo(): bool
    {
        return Turnstile::habilitado();
    }
}
