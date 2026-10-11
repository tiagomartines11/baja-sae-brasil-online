<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Upload tokens: what lets a phone keep sending entries for hours without
 * a forum session.
 *
 * A counter logs in once, opens the count page, and the page is handed a
 * token bound to that source. Forum sessions expire and phones lose
 * signal, and neither may ever stop an upload, so from then on the token
 * alone is the credential. Only its SHA-256 is stored: someone who reads
 * the database cannot upload as a counter.
 */
final class DeviceTokens
{
    private const TOKEN_BYTES = 32;
    private const LABEL_MAX_LENGTH = 255;
    private const BOX_SOURCE_NAME = 'Box';

    /** Sorts the pit-log source after every counting source wherever sources are listed. */
    private const BOX_SOURCE_ORDER = 1000;

    /**
     * Creates the device row and returns the plain token. This is the only
     * moment the plain token exists on the server.
     *
     * @param string|null $usuario who bound the device; null for an external (API) client
     * @param string $rotulo free text to recognise the device by (browser, "Transponder", ...)
     */
    public static function issue(ContagemFonte $fonte, ?string $usuario, string $rotulo): string
    {
        $plainToken = bin2hex(random_bytes(self::TOKEN_BYTES));

        $device = new ContagemDispositivo();
        $device->setFonteId($fonte->getFonteId());
        $device->setTokenHash(self::hash($plainToken));
        $device->setUsuario($usuario);
        $device->setRotulo(mb_substr($rotulo, 0, self::LABEL_MAX_LENGTH));
        $device->setCriadoEmMs(Clock::nowMs());
        $device->save();

        return $plainToken;
    }

    /**
     * Everything the upload endpoint needs to know about the device behind
     * a token, in ONE query: the device row and the source it is bound to.
     * Phones call that endpoint every five seconds, so this is the hottest
     * query of the whole feature. Revoked devices are returned too; the
     * caller decides what to answer.
     *
     * @return array{dispositivo_id: int, fonte_id: int, usuario: ?string, revogado_em_ms: ?int, sincronizado_ate_ms: ?int, primeiro_desvio_ms: ?int, fonte_nome: string, fonte_tipo: string, evento_id: string, prova_id: string}|null
     */
    public static function describeByToken(string $plainToken): ?array
    {
        if ($plainToken === '') {
            return null;
        }
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT d.dispositivo_id, d.fonte_id, d.usuario, d.revogado_em_ms, d.sincronizado_ate_ms,
                    d.primeiro_desvio_ms, f.nome AS fonte_nome, f.tipo AS fonte_tipo, f.evento_id, f.prova_id
               FROM contagem_dispositivo d
               JOIN contagem_fonte f ON f.fonte_id = d.fonte_id
              WHERE d.token_hash = ?'
        );
        $statement->execute([self::hash($plainToken)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $nullableInt = fn($value): ?int => $value === null ? null : (int) $value;
        return [
            'dispositivo_id' => (int) $row['dispositivo_id'],
            'fonte_id' => (int) $row['fonte_id'],
            'usuario' => $row['usuario'] === null ? null : (string) $row['usuario'],
            'revogado_em_ms' => $nullableInt($row['revogado_em_ms']),
            'sincronizado_ate_ms' => $nullableInt($row['sincronizado_ate_ms']),
            'primeiro_desvio_ms' => $nullableInt($row['primeiro_desvio_ms']),
            'fonte_nome' => (string) $row['fonte_nome'],
            'fonte_tipo' => (string) $row['fonte_tipo'],
            'evento_id' => (string) $row['evento_id'],
            'prova_id' => (string) $row['prova_id'],
        ];
    }

    /** @return ContagemDispositivo|null null when the token is unknown or was revoked */
    public static function findActiveByToken(string $plainToken): ?ContagemDispositivo
    {
        if ($plainToken === '') {
            return null;
        }
        $device = ContagemDispositivoQuery::create()->findOneByTokenHash(self::hash($plainToken));
        if ($device === null || $device->getRevogadoEmMs() !== null) {
            return null;
        }
        return $device;
    }

    public static function revoke(ContagemDispositivo $device): void
    {
        if ($device->getRevogadoEmMs() !== null) {
            return;
        }
        $device->setRevogadoEmMs(Clock::nowMs());
        $device->save();
    }

    /**
     * Revokes every device a user bound to one source. Used when a
     * controller removes that user from the source.
     *
     * @return int how many devices were revoked
     */
    public static function revokeForUserOnSource(ContagemFonte $fonte, string $usuario): int
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'UPDATE contagem_dispositivo
                SET revogado_em_ms = :agora
              WHERE fonte_id = :fonte AND usuario = :usuario AND revogado_em_ms IS NULL'
        );
        $statement->execute([
            ':agora' => Clock::nowMs(),
            ':fonte' => $fonte->getFonteId(),
            ':usuario' => $usuario,
        ]);
        return $statement->rowCount();
    }

    /**
     * The internal source pit-log devices are bound to, created the first
     * time someone opens the pit log of a prova. It never counts laps; it
     * exists so that pit-log phones get tokens the same way counters do.
     */
    public static function boxSourceFor(Prova $prova): ContagemFonte
    {
        $fonte = ContagemFonteQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->filterByTipo(Source::TIPO_BOX)
            ->orderByFonteId()
            ->findOne();
        if ($fonte !== null) {
            return $fonte;
        }

        $fonte = new ContagemFonte();
        $fonte->setEventoId($prova->getEventoId());
        $fonte->setProvaId($prova->getProvaId());
        $fonte->setNome(self::BOX_SOURCE_NAME);
        $fonte->setTipo(Source::TIPO_BOX);
        $fonte->setQualidadeTempo(Source::TEMPO_HUMANA);
        $fonte->setAtiva(true);
        $fonte->setOrdem(self::BOX_SOURCE_ORDER);
        $fonte->save();
        return $fonte;
    }

    private static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
