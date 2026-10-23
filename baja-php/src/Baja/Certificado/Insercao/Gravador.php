<?php

namespace Baja\Certificado\Insercao;

use Baja\Certificado\Token;
use Baja\Model\Map\ParticipanteTableMap;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use DateTimeImmutable;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;

/**
 * Writing a validated submission.
 *
 * One transaction for the whole batch, and that is the requirement rather
 * than an optimisation: a partial commit leaves the operator with no way to
 * know what landed. They pasted two thousand rows, five hundred of them are
 * now certificates, and the only way to find out which is to look. Rolling
 * the lot back gives them a sheet to fix and paste again.
 *
 * A single-entry save goes through here too, as a batch of one. It is the
 * same code because it is the same operation.
 */
final class Gravador
{
    public function __construct(private int $criadoPor)
    {
    }

    /**
     * @param array<int, Linha> $linhas
     *
     * @throws \LogicException if any row is not ready — the caller is meant
     *                         to have checked, and writing a batch that was
     *                         not fully resolved is the failure this whole
     *                         review flow exists to prevent
     */
    public function gravar(array $linhas, ?string $lote = null): Resultado
    {
        foreach ($linhas as $linha) {
            if (!$linha->podeGravar()) {
                throw new \LogicException(sprintf(
                    'Row %d is not ready to be written: %s',
                    $linha->numero,
                    $linha->temErro() ? 'it still has errors' : 'a warning has no resolution'
                ));
            }
        }

        // The caller may name the batch in advance. The paste page does, and
        // uses it to recognise a resubmitted form: if rows already exist under
        // the id its form carries, the commit already happened and doing it
        // again would create a second copy of the whole batch.
        $lote     = $lote !== null && Token::isWellFormed($lote) ? $lote : Token::generate();
        $agora    = new DateTimeImmutable();
        $criadas  = 0;
        $atualizadas = 0;
        $ignoradas   = 0;
        $eventos  = [];
        $renomeadas = 0;

        $con = Propel::getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            foreach ($linhas as $linha) {
                if ($linha->ehIgnorada()) {
                    $ignoradas++;
                    continue;
                }

                $eventos[(string) $linha->eventoId] = true;

                // "Correct the stored name" rewrites every row this document
                // is on file under, across every event — which is what makes
                // it useful and what makes it worth showing the count for
                // first. Done before the insert so the new row and the
                // corrected ones agree.
                $renomeadas += $this->corrigirNome($linha, $agora, $con);

                if ($this->querAtualizar($linha)) {
                    $this->atualizar($linha, $agora, $con);
                    $atualizadas++;
                    continue;
                }

                $this->inserir($linha, $lote, $agora, $con);
                $criadas++;
            }

            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();

            throw $e;
        }

        return new Resultado($lote, $criadas, $atualizadas, $ignoradas, $renomeadas, array_keys($eventos));
    }

    private function inserir(Linha $linha, string $lote, DateTimeImmutable $agora, ConnectionInterface $con): void
    {
        $row = new Participante();
        $row->setNome($linha->nome);
        $row->setFuncao($linha->funcao);
        $row->setEventoId($linha->eventoId);
        $row->setCpf($linha->cpf);
        $row->setDocumentoEstrangeiro($linha->documentoEstrangeiro);
        $row->setLoteId($lote);
        $row->setCriadoPor($this->criadoPor);
        $row->setCriadoEm($agora);
        $row->save($con);
    }

    /**
     * Overwrite the row this one duplicates.
     *
     * Only the name changes: the document, event and role are the natural key
     * that identified it as a duplicate in the first place, and the token
     * stays put because it is printed on a certificate somebody may already
     * hold.
     *
     * `lote_id` is deliberately left alone. Stamping this batch's id onto a
     * row that predates it would put that row inside a batch that can be
     * deleted wholesale — so undoing a mistaken paste would take a
     * certificate the paste never created.
     */
    private function atualizar(Linha $linha, DateTimeImmutable $agora, ConnectionInterface $con): void
    {
        $row = $linha->duplicado;

        if ($row === null) {
            return;
        }

        $row->setNome($linha->nome);
        $row->setCriadoPor($this->criadoPor);
        $row->setCriadoEm($agora);
        $row->save($con);
    }

    /**
     * Apply "correct the stored name" to every row filed under this document.
     *
     * @return int how many rows were rewritten
     */
    private function corrigirNome(Linha $linha, DateTimeImmutable $agora, ConnectionInterface $con): int
    {
        if (!self::corrigeNome($linha) || $linha->nome === null) {
            return 0;
        }

        $rewritten = 0;

        foreach ($linha->existentes() as $row) {
            if (trim((string) $row->getNome()) === $linha->nome) {
                continue;
            }

            $row->setNome($linha->nome);
            $row->setCriadoPor($this->criadoPor);
            $row->setCriadoEm($agora);
            $row->save($con);
            $rewritten++;
        }

        return $rewritten;
    }

    /** Whether this row's resolutions ask for the stored name to be rewritten. */
    public static function corrigeNome(Linha $linha): bool
    {
        foreach ([Problema::NOME_DIVERGENTE_LEVE, Problema::NOME_DIVERGENTE] as $codigo) {
            if ($linha->resolucao($codigo) === Problema::ATUALIZAR_NOME) {
                return true;
            }
        }

        return false;
    }

    private function querAtualizar(Linha $linha): bool
    {
        return $linha->duplicado !== null
            && $linha->resolucao(Problema::DUPLICADO) === Problema::ATUALIZAR;
    }

    /**
     * Every row of one batch, deleted.
     *
     * "Certificate rows are never deleted" protects certificates that have
     * been issued to people. A batch pasted in error two minutes ago is not
     * that, and leaving it in place means the mistake is permanent. This is
     * deliberately not automatic and deliberately not offered casually: the
     * caller is expected to have shown the event and the row count and asked.
     *
     * @return int rows deleted
     */
    public static function apagarLote(string $lote): int
    {
        if (!Token::isWellFormed($lote)) {
            return 0;
        }

        return ParticipanteQuery::create()->filterByLoteId($lote)->delete();
    }

    /**
     * Whether a batch id has already been used.
     *
     * What makes a resubmitted commit form recognisable. Content cannot answer
     * this — an operator may legitimately paste the same sheet twice for two
     * different events — but a batch id can, because it belongs to one
     * rendering of one form.
     */
    public static function loteExiste(string $lote): bool
    {
        return Token::isWellFormed($lote)
            && ParticipanteQuery::create()->filterByLoteId($lote)->count() > 0;
    }

    /** @return array<int, Participante> */
    public static function linhasDoLote(string $lote): array
    {
        if (!Token::isWellFormed($lote)) {
            return [];
        }

        return iterator_to_array(
            ParticipanteQuery::create()->filterByLoteId($lote)->orderByNome()->find(),
            false
        );
    }
}
