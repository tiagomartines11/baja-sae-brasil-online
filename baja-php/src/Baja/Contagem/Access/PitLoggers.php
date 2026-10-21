<?php
declare(strict_types=1);

namespace Baja\Contagem\Access;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\Prova;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Propel\Runtime\Propel;

/**
 * Who may log the pit lane and the fueling station of a prova: the people
 * holding the permission code {evento}_{prova}_BOX.
 *
 * The prova's controller is usually not an administrator, yet must be able
 * to hand the pit phones to volunteers on race morning, as they do with the
 * counters (CounterLinks). So the "Usuários" page of the lap counting can
 * give and take this one code. It is the only permission this class ever
 * touches: every other code of the user's row stays exactly as it was.
 *
 * Unlike the counters, the pit log has no table of links: one box source per
 * prova, and the code itself says who may use it. So the users' rows ARE the
 * list.
 */
final class PitLoggers
{
    /**
     * Lets a user log the pit lane and the fueling station of the prova.
     * Adding somebody who already may is a no-op.
     *
     * A user who has never used this system gets a row, but only after the
     * forum confirms the username exists, exactly as for a counter.
     *
     * @param string $username forum username, as typed by the controller
     * @throws \InvalidArgumentException with a message in Portuguese, fit to show to the controller
     */
    public static function add(Prova $prova, string $username): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new \InvalidArgumentException('Informe o nome de usuário do fórum.');
        }
        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $user = CounterLinks::findOrCreateUser($username);
            $permissions = (array) $user->getPermissions();
            $permissions[] = self::pitLogCode($prova);
            $user->setPermissions(array_values(array_unique(array_merge(User::SENTINEL_PERMISSIONS, $permissions))));
            $user->save();

            // A user whose pit phone was revoked by "remover" earlier would
            // otherwise stay blocked on the box source: adding them again
            // is how the controller lets them back in, as for a counter.
            DeviceTokens::release(DeviceTokens::boxSourceFor($prova), (string) $user->getUsername());
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * Takes the pit log away from a user.
     *
     * The code goes, and every device of theirs on the prova's box source is
     * revoked at once: the phone at the pit lane stops before its next
     * upload, and keeps what it recorded for export. The user's other codes
     * (counting, other provas, admin) are left alone, and so are their
     * devices on the counting sources. The row itself stays even if nothing
     * is left in it: deleting accounts is the administrators' job.
     */
    public static function remove(Prova $prova, string $username): void
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $user = UserQuery::create()->findOneByUsername($username);
            if ($user !== null) {
                $code = self::pitLogCode($prova);
                $user->setPermissions(array_values(array_filter(
                    (array) $user->getPermissions(),
                    fn($permission) => $permission !== $code
                )));
                $user->save();
                $username = (string) $user->getUsername();
            }
            DeviceTokens::revokeForUserOnSource(DeviceTokens::boxSourceFor($prova), $username);
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * The users holding the pit log code of the prova, by name, with when
     * each was last heard from on a pit phone of this prova.
     *
     * The controller (prova code) and administrators may log stops too
     * without being on this list; it lists who was GIVEN the pit log.
     *
     * @return array<int, array{usuario: string, visto_em_ms: ?int}>
     */
    public static function listForProva(Prova $prova): array
    {
        $code = self::pitLogCode($prova);
        $lastSeen = self::lastSeenOnTheBoxSource($prova);

        $list = [];
        foreach (UserQuery::create()->orderByUsername()->find() as $user) {
            if (in_array($code, (array) $user->getPermissions(), true)) {
                $name = (string) $user->getUsername();
                $list[] = ['usuario' => $name, 'visto_em_ms' => $lastSeen[$name] ?? null];
            }
        }
        return $list;
    }

    /** The code that opens the pit log of this prova, e.g. "26BR_END_BOX". */
    public static function pitLogCode(Prova $prova): string
    {
        return $prova->getEventoId() . '_' . $prova->getProvaId() . ContagemAccess::PIT_LOG_SUFFIX;
    }

    /** @return array<string, int> username => last contact of any of their devices on the box source */
    private static function lastSeenOnTheBoxSource(Prova $prova): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT d.usuario, MAX(d.visto_em_ms) AS visto_em_ms
               FROM contagem_dispositivo d
               JOIN contagem_fonte f ON f.fonte_id = d.fonte_id
              WHERE f.evento_id = :evento AND f.prova_id = :prova AND f.tipo = :tipo
                AND d.usuario IS NOT NULL AND d.visto_em_ms IS NOT NULL
           GROUP BY d.usuario'
        );
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId(), ':tipo' => Source::TIPO_BOX]);
        $seen = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $seen[(string) $row['usuario']] = (int) $row['visto_em_ms'];
        }
        return $seen;
    }
}
