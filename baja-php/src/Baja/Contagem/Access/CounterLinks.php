<?php
declare(strict_types=1);

namespace Baja\Contagem\Access;

use Baja\Auth\SessionStore;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteUsuario;
use Baja\Model\ContagemFonteUsuarioQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Propel\Runtime\Propel;

/**
 * Which users count on which source, and the permission that goes with it.
 *
 * A controller of a prova is usually not an administrator, yet must be able
 * to hand phones to volunteers on race morning. So linking a user to a
 * source grants that user the {evento}_{prova}_CONT code automatically, and
 * removing their last link for the prova takes it away again. That one code
 * is the only permission this class ever touches.
 */
final class CounterLinks
{
    /** @var callable|null replaces the phpBB lookup; tests only */
    private static $forumLookup = null;

    /**
     * Lets a user count on a source.
     *
     * If the person has never used this system (no row in `user`), the row
     * is created, but only after the forum confirms the username exists:
     * a typo must not create an account nobody can log in to.
     *
     * @param string $username forum username, as typed by the controller
     * @param string $addedBy the controller's username, kept for the record
     * @throws \InvalidArgumentException with a message in Portuguese, fit to show to the controller
     */
    public static function link(ContagemFonte $fonte, string $username, string $addedBy): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new \InvalidArgumentException('Informe o nome de usuário do fórum.');
        }
        if ($fonte->getTipo() !== Source::TIPO_MANUAL) {
            throw new \InvalidArgumentException('Só é possível vincular contadores a uma contagem manual.');
        }

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $user = self::findOrCreateUser($username);

            $permissions = (array) $user->getPermissions();
            $permissions[] = self::counterCode($fonte);
            $user->setPermissions(array_values(array_unique(array_merge(User::SENTINEL_PERMISSIONS, $permissions))));
            $user->save();

            // The spelling stored on the link is the one in `user`, so that
            // it matches what the session reports when that person logs in.
            $canonicalUsername = (string) $user->getUsername();
            $link = ContagemFonteUsuarioQuery::create()->findPk([$fonte->getFonteId(), $canonicalUsername]);
            if ($link === null) {
                $link = new ContagemFonteUsuario();
                $link->setFonteId($fonte->getFonteId());
                $link->setUsuario($canonicalUsername);
                $link->setAdicionadoPor($addedBy);
                $link->setCriadoEmMs(Clock::nowMs());
                $link->save();
            } else {
                // Linking somebody who is already linked is how a controller
                // says "let this person back in" after revoking their
                // device: a revoked device only blocks a user while it is
                // newer than the link (see DeviceTokens::isBlocked()).
                DeviceTokens::release($fonte, $canonicalUsername);
            }

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * Takes a user off a source.
     *
     * Their devices for that source are revoked at once: the phone they
     * were using answers "token inválido" on its next upload and keeps its
     * local data for export. If this was their last source in the prova
     * the _CONT code goes too. The `user` row itself is left in place even
     * if no permission remains; deleting accounts is the administrators'
     * job (admin_usuario.php), not a side effect of this page.
     */
    public static function unlink(ContagemFonte $fonte, string $username): void
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $link = ContagemFonteUsuarioQuery::create()->findPk([$fonte->getFonteId(), $username]);
            if ($link !== null) {
                $link->delete();
            }

            DeviceTokens::revokeForUserOnSource($fonte, $username);

            if (self::countLinksInProva($fonte, $username) === 0) {
                $user = UserQuery::create()->findOneByUsername($username);
                if ($user !== null) {
                    $code = self::counterCode($fonte);
                    $user->setPermissions(array_values(array_filter(
                        (array) $user->getPermissions(),
                        fn($permission) => $permission !== $code
                    )));
                    $user->save();
                }
            }

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * The users linked to a source, with when each was last seen counting
     * on it (the last contact from any device they bound to this source).
     *
     * @return array<int, array{usuario: string, visto_em_ms: ?int}>
     */
    public static function listForSource(ContagemFonte $fonte): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT u.usuario, MAX(d.visto_em_ms) AS visto_em_ms
               FROM contagem_fonte_usuario u
          LEFT JOIN contagem_dispositivo d ON d.fonte_id = u.fonte_id AND d.usuario = u.usuario
              WHERE u.fonte_id = :fonte
           GROUP BY u.usuario
           ORDER BY u.usuario'
        );
        $statement->execute([':fonte' => $fonte->getFonteId()]);

        $list = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $list[] = [
                'usuario' => (string) $row['usuario'],
                'visto_em_ms' => $row['visto_em_ms'] === null ? null : (int) $row['visto_em_ms'],
            ];
        }
        return $list;
    }

    /**
     * Tests replace the phpBB lookup, which needs the forum database.
     *
     * @param callable|null $lookup fn(string $username): ?array{username: string}; null restores the real one
     */
    public static function setForumLookup(?callable $lookup): void
    {
        self::$forumLookup = $lookup;
    }

    /** The permission code a counter of this source's prova holds, e.g. "26BR_END_CONT". */
    public static function counterCode(ContagemFonte $fonte): string
    {
        return $fonte->getEventoId() . '_' . $fonte->getProvaId() . ContagemAccess::COUNTER_SUFFIX;
    }

    private static function findOrCreateUser(string $username): User
    {
        $user = UserQuery::create()->findOneByUsername($username);
        if ($user !== null) {
            return $user;
        }

        $forumUser = self::lookupInForum($username);
        if ($forumUser === null) {
            throw new \InvalidArgumentException(
                'O usuário "' . $username . '" não existe no fórum. Confira o nome e tente de novo.'
            );
        }

        $user = new User();
        // The forum's own spelling: it is what the session will report.
        $user->setUsername((string) ($forumUser['username'] ?? $username));
        $user->setPermissions(User::SENTINEL_PERMISSIONS);
        return $user;
    }

    /** @return array<string, mixed>|null the forum's row for the user, null when there is none */
    private static function lookupInForum(string $username): ?array
    {
        if (self::$forumLookup !== null) {
            return (self::$forumLookup)($username);
        }
        return (new SessionStore())->lookupUserByUsername($username);
    }

    /** How many sources of the same prova the user is still linked to. */
    private static function countLinksInProva(ContagemFonte $fonte, string $username): int
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT COUNT(*)
               FROM contagem_fonte_usuario u
               JOIN contagem_fonte f ON f.fonte_id = u.fonte_id
              WHERE f.evento_id = :evento AND f.prova_id = :prova AND u.usuario = :usuario'
        );
        $statement->execute([
            ':evento' => $fonte->getEventoId(),
            ':prova' => $fonte->getProvaId(),
            ':usuario' => $username,
        ]);
        return (int) $statement->fetchColumn();
    }
}
