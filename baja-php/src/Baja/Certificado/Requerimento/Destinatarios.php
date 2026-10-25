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
 * CERT_REPORT_EMAILS decides, when it holds at least one valid address: those
 * addresses and nobody else. It replaces the derived list rather than adding
 * to it, so that a deployment can send reports to one shared inbox without
 * every admin receiving a copy, and the forum is not even asked.
 *
 * Blank, the list is derived: everybody who can act on a report, which is the
 * same permission that opens the insertion pages — `certificados`, plus
 * `admin`, which implies it everywhere else in this system. Deriving the list
 * from the permission rather than keeping a mailing list means the two cannot
 * drift: somebody granted the permission starts receiving reports, and
 * somebody who loses it stops, without a second place to remember. The
 * addresses come from the forum. See Baja\Auth\PhpbbEmails for why there is
 * no email column here.
 *
 * Set, but with no valid address in it, is a typo, not a request to notify
 * nobody: the derived list is used instead and the misconfiguration is
 * logged. A report with no recipient is the one outcome worth avoiding — the
 * person has been told it was received.
 */
final class Destinatarios
{
    /** Addresses, comma-separated. When any is valid, used instead of the derived list. */
    public const ENV_EXTRA = 'CERT_REPORT_EMAILS';

    /**
     * Every address to notify, deduplicated and validated.
     *
     * @return array<int, string>
     */
    public static function todos(): array
    {
        return self::escolher(
            (string) Env::get(self::ENV_EXTRA, ''),
            static fn (): array => self::daEquipe()
        );
    }

    /**
     * The rule itself, given the variable's value and a way to get the
     * derived list.
     *
     * The derived list is passed as a callable rather than a value because
     * computing it is the expensive part — a scan of `user` and a query
     * against the forum database — and it is only needed when the variable
     * does not decide. todos() is the only production caller; the tests call
     * this directly so they need neither a database nor a forum.
     *
     * @param callable(): array<int, string> $daEquipe
     * @return array<int, string>
     */
    public static function escolher(string $configurado, callable $daEquipe): array
    {
        $configurados = self::separar($configurado);
        $doEnv        = self::validosSemRepeticao($configurados);

        if ($doEnv !== []) {
            return $doEnv;
        }

        if (trim($configurado) !== '') {
            // Only a count: the variable is staff configuration, but a log
            // line is read by more people than the .env file is.
            error_log(
                'Certificado\Requerimento\Destinatarios: ' . self::ENV_EXTRA . ' is set but none of its '
                . count($configurados) . ' entries is a valid address. Notifying the users with the "'
                . Acesso::PERMISSAO . '" permission instead; fix the variable.'
            );
        }

        $unicos = self::validosSemRepeticao($daEquipe());

        if ($unicos === []) {
            error_log(
                'Certificado\Requerimento\Destinatarios: nobody to notify — ' . self::ENV_EXTRA
                . (trim($configurado) === '' ? ' is blank' : ' has no valid address')
                . ', and no user holding the "' . Acesso::PERMISSAO
                . '" or "admin" permission has a valid email in their forum profile.'
                . ' Reports are still being saved.'
            );
        }

        return $unicos;
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

    /**
     * @param array<int, string> $enderecos
     * @return array<int, string>
     */
    private static function validosSemRepeticao(array $enderecos): array
    {
        // Case-insensitively, because the same mailbox reached from two
        // places, or typed twice with different capitals, is one recipient.
        $unicos = [];
        foreach ($enderecos as $endereco) {
            if (Mailer::enderecoValido($endereco)) {
                $unicos[strtolower($endereco)] = $endereco;
            }
        }

        return array_values($unicos);
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
    private static function separar(string $bruto): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $bruto))));
    }
}
