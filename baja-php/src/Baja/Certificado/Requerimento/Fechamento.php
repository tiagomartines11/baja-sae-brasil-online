<?php

namespace Baja\Certificado\Requerimento;

use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Model\Map\CertificadoRequerimentoTableMap;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;

/**
 * What saving the staff form does to a report (BAJAAPP-88).
 *
 * It used to live inline in juiz/certificados_requerimentos.php, when saving
 * only ever touched one row and sent nothing. Now a save can close several
 * rows and email several people, and which of them gets an email is the
 * part most worth a test, so it lives here, with the mailer handed in the
 * way Recepcao takes its own (padrao() is the real one; a test passes a
 * stand-in that counts).
 *
 * The rules, in one place:
 *
 *  - Closing (resolvido or recusado) needs a reply to the participant,
 *    `resposta`. The internal note, `resolucao`, is optional and never sent.
 *  - A save owes the participant an email when the report ends up closed and
 *    something they would read changed: it was open before, it moved
 *    between resolvido and recusado, or the reply text changed. Saving a
 *    closed report with only the internal note edited sends nothing.
 *  - Moving to "em análise" sends nothing. Reopening sends nothing, and
 *    clears who closed it and when; closing again later sends again.
 *  - When the report has open duplicates and the box is ticked, they are
 *    closed with it: same status, reply and note, each with its own
 *    `resolvido_por` / `resolvido_em`, in one transaction. Each then gets
 *    its own email to its own address.
 *  - Emails go out only after the commit. A send that fails leaves
 *    `respondido_em` null and changes nothing else: the closing stands, and
 *    the staff page offers "Reenviar".
 */
final class Fechamento
{
    /** The longest reply or note. Matches both columns. */
    public const TEXTO_MAX = 1000;

    /**
     * @param \Closure(CertificadoRequerimento): bool $enviarResposta whether the relay accepted it
     */
    public function __construct(private \Closure $enviarResposta)
    {
    }

    /** The real thing: the reply over SMTP. */
    public static function padrao(): self
    {
        return new self(static fn (CertificadoRequerimento $r): bool => Aviso::responderAoParticipante($r));
    }

    /**
     * The open reports that point at this one, oldest first.
     *
     * The ones the staff form offers to close together with it.
     *
     * @return CertificadoRequerimento[]
     */
    public static function duplicadosAbertos(CertificadoRequerimento $requerimento): array
    {
        return iterator_to_array(
            CertificadoRequerimentoQuery::create()
                ->filterByDuplicadoDe($requerimento->getRequerimentoId())
                ->filterByStatus(Situacao::ABERTOS, Criteria::IN)
                ->orderByCriadoEm(Criteria::ASC)
                ->orderByRequerimentoId()
                ->find(),
            false
        );
    }

    /**
     * Whether this report still owes its participant the closing email.
     *
     * Closed, with a reply, and no record of the relay taking it. A report
     * closed before BAJAAPP-88 has no reply, so it owes nothing: there is no
     * text to send, and inventing one is not this code's job.
     */
    public static function respostaPendente(CertificadoRequerimento $requerimento): bool
    {
        return Situacao::fechada((string) $requerimento->getStatus())
            && (string) $requerimento->getResposta() !== ''
            && $requerimento->getRespondidoEm() === null;
    }

    /**
     * Save the staff form.
     *
     * @param string $status            the status chosen
     * @param string $resposta          the reply to the participant, already trimmed
     * @param string $resolucao         the internal note, already trimmed
     * @param bool   $fecharDuplicados  the "fechar também os duplicados" box
     */
    public function salvar(
        CertificadoRequerimento $requerimento,
        int $usuarioId,
        string $status,
        string $resposta,
        string $resolucao,
        bool $fecharDuplicados
    ): Salvamento {
        $erro = self::erro($status, $resposta, $resolucao);

        if ($erro !== null) {
            return Salvamento::recusado($erro);
        }

        $fechando = Situacao::fechada($status);

        // Read before anything is set: these are what the participant was
        // last told, and the comparison is against them.
        $statusAntes   = (string) $requerimento->getStatus();
        $respostaAntes = (string) $requerimento->getResposta();

        $respostaDevida = $fechando
            && (!Situacao::fechada($statusAntes) || $statusAntes !== $status || $respostaAntes !== $resposta);

        /** @var CertificadoRequerimento[] $aResponder every row whose participant is owed the email */
        $aResponder = [];
        $fechadosJunto = 0;

        $con = Propel::getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            self::aplicar($requerimento, $usuarioId, $status, $resposta, $resolucao, $respostaDevida);
            $requerimento->save($con);

            if ($respostaDevida) {
                $aResponder[] = $requerimento;
            }

            if ($fechando && $fecharDuplicados) {
                // Asked again here, inside the transaction, rather than
                // trusting the count the form showed: a duplicate that
                // arrived after the page was drawn is still the same report.
                foreach (self::duplicadosAbertos($requerimento) as $duplicado) {
                    self::aplicar($duplicado, $usuarioId, $status, $resposta, $resolucao, true);
                    $duplicado->save($con);
                    $aResponder[] = $duplicado;
                    $fechadosJunto++;
                }
            }

            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();

            throw $e;
        }

        /*
         * After the commit, never inside it. Each send is an SMTP conversation
         * of up to 15 seconds, and holding row locks for that long blocks the
         * next person to touch these reports. It is also the right order for
         * a failure: the closing is the decision, the email is the news of
         * it, and a relay being down must not undo the decision.
         */
        $naoEnviados = [];

        foreach ($aResponder as $linha) {
            if (!$this->enviar($linha)) {
                $naoEnviados[] = (string) $linha->getRequerimentoId();
            }
        }

        return Salvamento::salvo(count($aResponder), $naoEnviados, $fechadosJunto);
    }

    /**
     * Send the closing email again, after a send that failed.
     *
     * Only when one is still owed. A second press of "Reenviar", or one on a
     * page left open after another member of staff resent it, finds
     * `respondido_em` set and sends nothing.
     */
    public function reenviar(CertificadoRequerimento $requerimento): Salvamento
    {
        if (!self::respostaPendente($requerimento)) {
            return Salvamento::recusado('Não há resposta pendente de envio para este requerimento.');
        }

        $naoEnviados = $this->enviar($requerimento) ? [] : [(string) $requerimento->getRequerimentoId()];

        return Salvamento::salvo(1, $naoEnviados, 0);
    }

    /** What is wrong with the form, or null. */
    private static function erro(string $status, string $resposta, string $resolucao): ?string
    {
        if (!Situacao::valida($status)) {
            return 'Escolha uma situação.';
        }

        if (Situacao::fechada($status) && $resposta === '') {
            /*
             * The participant is told the report is closed, by email and on
             * the status page, and "Resolvido" with nothing under it answers
             * none of the questions they filed it to ask.
             *
             * "Em análise" is exempt for the reason the old note was: it means
             * "I have picked this up", ticked before anybody has looked into
             * anything, and so before there is anything to say.
             */
            return 'Escreva a resposta ao participante antes de encerrar o requerimento.';
        }

        if (mb_strlen($resposta, 'UTF-8') > self::TEXTO_MAX) {
            return 'A resposta ao participante passa de ' . self::TEXTO_MAX . ' caracteres.';
        }

        if (mb_strlen($resolucao, 'UTF-8') > self::TEXTO_MAX) {
            return 'A anotação interna passa de ' . self::TEXTO_MAX . ' caracteres.';
        }

        return null;
    }

    /** Set the form's values on one row. Saving is the caller's. */
    private static function aplicar(
        CertificadoRequerimento $linha,
        int $usuarioId,
        string $status,
        string $resposta,
        string $resolucao,
        bool $respostaDevida
    ): void {
        $linha->setStatus($status);
        $linha->setResposta($resposta !== '' ? $resposta : null);
        $linha->setResolucao($resolucao !== '' ? $resolucao : null);

        if (!Situacao::fechada($status)) {
            /*
             * Reopening, or moving to "em análise", clears the attribution
             * rather than leaving the last person to close it named against a
             * report that is open again. `resolvido_por` means what it says;
             * reusing it for "who picked this up" would put "Fechado por" on
             * the page about a report nobody has closed.
             *
             * `respondido_em` goes too. It records the email for the current
             * closing, and an open report has none; the next closing owes a
             * new one.
             */
            $linha->setResolvidoPor(null);
            $linha->setResolvidoEm(null);
            $linha->setRespondidoEm(null);

            return;
        }

        if ($respostaDevida) {
            // A new decision the participant will hear about: it is this
            // person's, now. A save that only edits the internal note keeps
            // the original closer and date, because nothing was decided.
            $linha->setResolvidoPor($usuarioId);
            $linha->setResolvidoEm(new \DateTime());
            $linha->setRespondidoEm(null);
        }
    }

    /** Send one reply, and record it when the relay accepts. */
    private function enviar(CertificadoRequerimento $linha): bool
    {
        if (!($this->enviarResposta)($linha)) {
            // The protocol only: it says nothing about a person, and it is
            // what somebody reading the log needs to find the row.
            error_log('Certificado\Requerimento\Fechamento: the reply for ' . $linha->getRequerimentoId() . ' was not sent.');

            return false;
        }

        $linha->setRespondidoEm(new \DateTime());
        $linha->save();

        return true;
    }
}
