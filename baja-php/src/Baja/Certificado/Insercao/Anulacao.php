<?php

namespace Baja\Certificado\Insercao;

use Baja\Certificado\Token;
use Baja\Model\Map\ParticipanteTableMap;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use DateTimeImmutable;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Voiding a certificate, and undoing that.
 *
 * Not deletion, and the difference is the whole point. Deleting a lote removes
 * rows, and exists to undo a paste minutes old where nothing has reached
 * anybody. This reaches a certificate issued years ago that somebody may be
 * holding, so the row survives: what changes is that it stops verifying, and
 * the record gains who withdrew it and why.
 *
 * The reason is required, because it is the first thing anybody asks
 * afterwards and the only one nobody can reconstruct.
 */
final class Anulacao
{
    /** `anulado_motivo` is VARCHAR(255), latin1 like every other text column here. */
    public const MOTIVO_MAX = 255;

    public function __construct(private int $usuario)
    {
    }

    /**
     * Why a reason is not acceptable, or an empty array.
     *
     * The character-set check is the same one the insertion pages run, for the
     * same reason: `anulado_motivo` is latin1, and MySQL answers a character it
     * cannot represent by refusing the statement — which inside this
     * transaction would abort every other row with it.
     *
     * @return array<int, string>
     */
    public static function problemasDoMotivo(string $motivo): array
    {
        $motivo = Texto::limpar($motivo);
        $erros  = [];

        if ($motivo === '') {
            $erros[] = 'Diga por que estes certificados estão sendo anulados. '
                . 'É a única parte do registro que ninguém consegue reconstruir depois.';

            return $erros;
        }

        if (!Texto::utf8Valido($motivo)) {
            $erros[] = 'O motivo não está em UTF-8 e não pôde ser lido.';

            return $erros;
        }

        $ruins = Texto::naoArmazenaveis($motivo);
        if ($ruins !== []) {
            $erros[] = 'O motivo contém caracteres que a base não armazena: '
                . implode(', ', array_map([Texto::class, 'descrever'], $ruins)) . '.';
        }

        if (mb_strlen($motivo, 'UTF-8') > self::MOTIVO_MAX) {
            $erros[] = sprintf(
                'O motivo tem %d caracteres; o limite é %d.',
                mb_strlen($motivo, 'UTF-8'),
                self::MOTIVO_MAX
            );
        }

        return $erros;
    }

    /**
     * Rows for a list of tokens, in a stable order.
     *
     * Used to show exactly what is about to be voided before it is. Malformed
     * tokens are dropped rather than reported: they cannot name a row, and the
     * count the caller echoes back is what catches a list that changed.
     *
     * @param array<int, string> $tokens
     * @return array<int, Participante>
     */
    public static function linhas(array $tokens): array
    {
        $limpos = array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => Token::isWellFormed($token)
        )));

        if ($limpos === []) {
            return [];
        }

        return iterator_to_array(
            ParticipanteQuery::create()
                ->filterByToken($limpos, Criteria::IN)
                ->orderByEventoId(Criteria::DESC)
                ->orderByNome()
                ->find(),
            false
        );
    }

    /**
     * Void every certificate in the list that is not already void.
     *
     * One transaction, for the same reason a batch insert is one: half a
     * voiding leaves the operator unable to say which certificates still
     * verify.
     *
     * @param array<int, string> $tokens
     * @return int how many were voided
     */
    public function anular(array $tokens, string $motivo): int
    {
        $motivo = Texto::limpar($motivo);

        if (self::problemasDoMotivo($motivo) !== []) {
            throw new \LogicException('Refusing to void without a usable reason.');
        }

        $agora = new DateTimeImmutable();
        $n     = 0;

        $con = Propel::getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            foreach (self::linhas($tokens) as $linha) {
                // Already void: left exactly as it is. Overwriting would
                // replace the original reason and date with this one, which is
                // the opposite of what an audit column is for.
                if ($linha->getAnuladoEm() !== null) {
                    continue;
                }

                $linha->setAnuladoEm($agora);
                $linha->setAnuladoPor($this->usuario);
                $linha->setAnuladoMotivo($motivo);
                $linha->save($con);
                $n++;
            }

            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();

            throw $e;
        }

        return $n;
    }

    /**
     * Undo a voiding.
     *
     * Honest about what it costs: clearing the three columns is the only way
     * for the certificate to verify again, and it takes the record of the
     * voiding with it. What survives is criado_por and criado_em, restamped —
     * which on this branch already mean "who last asserted this record", the
     * same as they do after a name correction. Keeping the voiding itself would
     * need a history of state changes rather than a current state, which is a
     * larger thing than this.
     *
     * @param array<int, string> $tokens
     * @return int how many were restored
     */
    public function restaurar(array $tokens): int
    {
        $agora = new DateTimeImmutable();
        $n     = 0;

        $con = Propel::getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            foreach (self::linhas($tokens) as $linha) {
                if ($linha->getAnuladoEm() === null) {
                    continue;
                }

                $linha->setAnuladoEm(null);
                $linha->setAnuladoPor(null);
                $linha->setAnuladoMotivo(null);
                $linha->setCriadoPor($this->usuario);
                $linha->setCriadoEm($agora);
                $linha->save($con);
                $n++;
            }

            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();

            throw $e;
        }

        return $n;
    }
}
