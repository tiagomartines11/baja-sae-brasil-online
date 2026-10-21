<?php

namespace Baja\Certificado\Requerimento;

use Baja\Auth\PhpbbEmails;
use Baja\Certificado\Insercao\Acesso;
use Baja\Model\UserQuery;
use Baja\Util\Env;
use Baja\Util\Mailer;

/**
 * Who gets told about a new report.
 *
 * Everybody who can act on one, which is the same permission that opens the
 * insertion pages — `certificados`, plus `admin`, which implies it everywhere
 * else in this system. Deriving the list from the permission rather than
 * keeping a mailing list means the two cannot drift: somebody granted the
 * permission starts receiving reports, and somebody who loses it stops,
 * without a second place to remember.
 *
 * The addresses come from the forum. See Baja\Auth\PhpbbEmails for why there
 * is no email column here.
 *
 * CERT_REPORT_EMAILS is added to that list, never used instead of it. It is
 * there for the two cases the derived list cannot cover: a shared inbox that
 * belongs to no forum account, and a deployment where the phpBB read fails.
 * A report with no recipient is the one outcome worth avoiding — the person
 * has been told it was received.
 */
final class Destinatarios
{
    /** Extra addresses, comma-separated. Added to the derived list. */
    public const ENV_EXTRA = 'CERT_REPORT_EMAILS';

    /**
     * Every address to notify, deduplicated and validated.
     *
     * @return array<int, string>
     */
    public static function todos(): array
    {
        $enderecos = array_merge(self::doEnv(), self::daEquipe());

        // Case-insensitively, because the same mailbox reached from a forum
        // profile and from the env list is one recipient, not two.
        $unicos = [];
        foreach ($enderecos as $endereco) {
            if (Mailer::enderecoValido($endereco)) {
                $unicos[strtolower($endereco)] = $endereco;
            }
        }

        if ($unicos === []) {
            error_log(
                'Certificado\Requerimento\Destinatarios: nobody to notify — no user holds the "'
                . Acesso::PERMISSAO . '" permission with a forum email, and ' . self::ENV_EXTRA
                . ' is unset. Reports are still being saved.'
            );
        }

        return array_values($unicos);
    }

    /**
     * The usernames that hold the permission.
     *
     * A full scan of `user`, which is a staff table with tens of rows, and
     * the permission list is a serialized column that cannot be filtered in
     * SQL anyway. hasPermission() rather than a string search on the column:
     * it is the same method Session uses to decide access, and a report going
     * to somebody who cannot open the page it links to would be its own bug.
     *
     * @return array<int, string>
     */
    public static function usuariosComPermissao(): array
    {
        $usernames = [];

        foreach (UserQuery::create()->find() as $usuario) {
            if ($usuario->hasPermission(Acesso::PERMISSAO) || $usuario->hasPermission('admin')) {
                $username = trim((string) $usuario->getUsername());
                if ($username !== '') {
                    $usernames[] = $username;
                }
            }
        }

        return $usernames;
    }

    /** @return array<int, string> */
    private static function daEquipe(): array
    {
        $usernames = self::usuariosComPermissao();

        if ($usernames === []) {
            return [];
        }

        return array_values((new PhpbbEmails())->porUsername($usernames));
    }

    /** @return array<int, string> */
    private static function doEnv(): array
    {
        $bruto = (string) Env::get(self::ENV_EXTRA, '');

        if ($bruto === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $bruto))));
    }
}
