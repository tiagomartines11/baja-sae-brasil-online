<?php

namespace Baja\Certificado\Requerimento;

use Baja\Certificado\Token;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Model\Map\CertificadoRequerimentoTableMap;
use Propel\Runtime\Propel;

/**
 * Writing one report down, once.
 *
 * Small on purpose. The row is a claim, not a change: nothing here touches
 * `participantes`, and the only way a certificate changes as a result of one
 * of these is a person with the `certificados` permission deciding it should
 * and doing it through the insertion pages, which keep their own audit trail.
 *
 * "Once" is BAJAAPP-89. The same report pressed six times used to be six rows
 * and six staff alerts. registrar() now looks for an open report it repeats
 * (Duplicata says what "repeats" means) before writing anything, and does the
 * looking and the writing while holding a lock, so that six presses arriving
 * together are still answered one at a time.
 */
final class Gravador
{
    /**
     * How long a submission waits for another submission of the same report
     * to finish being written.
     *
     * What the lock covers is one SELECT and one INSERT — milliseconds. Six
     * presses queue for a few of those each. Anything near this long means
     * the database is in trouble, and then writing the report anyway is
     * better than refusing it.
     */
    public const ESPERA_TRAVA_SEGUNDOS = 5;

    /**
     * Write this submission down, unless it is a report already open.
     *
     * See Registro for the four outcomes and what each one owes the person
     * and the staff.
     */
    public static function registrar(Formulario $formulario, int $esperaTrava = self::ESPERA_TRAVA_SEGUNDOS): Registro
    {
        $con   = Propel::getWriteConnection(CertificadoRequerimentoTableMap::DATABASE_NAME);
        $trava = Duplicata::nomeDaTrava($formulario);
        $temTrava = false;

        try {
            $consulta = $con->prepare('SELECT GET_LOCK(?, ?)');
            $consulta->execute([$trava, $esperaTrava]);
            // 1 taken, 0 timed out, NULL an error. Only 1 is ours to release.
            $temTrava = (string) $consulta->fetchColumn() === '1';
        } catch (\Throwable $e) {
            error_log('Certificado\Requerimento\Gravador: GET_LOCK failed: ' . $e->getMessage());
        }

        if (!$temTrava) {
            // Carry on without it. The cost is a rare duplicate row, which
            // staff can close; the alternative is telling somebody their
            // report about their own certificate could not be taken. The lock
            // name is a digest and says nothing about whose report this is.
            error_log('Certificado\Requerimento\Gravador: could not take lock ' . $trava . '; writing without it.');
        }

        try {
            return self::registrarSemTrava($formulario);
        } finally {
            if ($temTrava) {
                try {
                    $con->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
                } catch (\Throwable $e) {
                    // The lock dies with the connection anyway; PHP-FPM ends
                    // the request and the next one gets a fresh session.
                    error_log('Certificado\Requerimento\Gravador: could not release lock ' . $trava . ': ' . $e->getMessage());
                }
            }
        }
    }

    /** The check and the write. Only ever called with the lock held, or after failing to get it. */
    private static function registrarSemTrava(Formulario $formulario): Registro
    {
        $abertas = Duplicata::abertas($formulario);

        if ($abertas !== []) {
            $doMesmoEmail = Duplicata::doMesmoEmail($abertas, $formulario->email());

            if ($doMesmoEmail !== null) {
                // The same person pressing the button again. Nothing new to
                // store and nobody new to tell.
                return new Registro(Registro::REPETIDO, $doMesmoEmail);
            }

            return new Registro(
                Registro::DUPLICADO,
                self::gravar($formulario, Duplicata::original($abertas)->getRequerimentoId())
            );
        }

        // Asked before the insert, or it finds the row this call is about
        // to write.
        $situacao = Duplicata::certificadoJaReportado($formulario) ? Registro::CERTIFICADO_JA_REPORTADO : Registro::NOVO;

        return new Registro($situacao, self::gravar($formulario));
    }

    public static function gravar(Formulario $formulario, ?string $duplicadoDe = null): CertificadoRequerimento
    {
        $requerimento = new CertificadoRequerimento();
        $requerimento->setRequerimentoId(self::idInedito());
        $requerimento->setCaso($formulario->caso());
        $requerimento->setToken($formulario->token());
        $requerimento->setEventoId($formulario->eventoCodigo());
        $requerimento->setEventoTexto($formulario->eventoTexto());
        $requerimento->setDocumento($formulario->documento());
        $requerimento->setNome($formulario->nome());
        $requerimento->setEmail($formulario->email());
        $requerimento->setTelefone($formulario->telefone());
        $requerimento->setDescricao($formulario->descricao());
        $requerimento->setCriadoEm(new \DateTime());
        $requerimento->setStatus('aberto');
        $requerimento->setDuplicadoDe($duplicadoDe);
        $requerimento->save();

        return $requerimento;
    }

    /**
     * Record that the notification for this report was dealt with.
     *
     * Either the relay accepted the alert, or this report was one staff had
     * already been told about. Both mean nobody is waiting on an email that
     * never went out, which is the one thing a null `avisado_em` is there to
     * show on the staff page.
     */
    public static function marcarAvisado(CertificadoRequerimento $requerimento): void
    {
        $requerimento->setAvisadoEm(new \DateTime());
        $requerimento->save();
    }

    /**
     * A report id that is not already taken.
     *
     * 128 bits of randomness makes a collision a theoretical concern rather
     * than a practical one, but the id is a primary key and a duplicate would
     * surface as an exception on a page that has already told somebody their
     * report was received. Same shape as Insercao\Gravador's lote ids.
     */
    private static function idInedito(): string
    {
        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            $id = Token::generate();

            if (!CertificadoRequerimentoQuery::create()->filterByRequerimentoId($id)->exists()) {
                return $id;
            }
        }

        throw new \RuntimeException('Could not generate an unused report id.');
    }
}
