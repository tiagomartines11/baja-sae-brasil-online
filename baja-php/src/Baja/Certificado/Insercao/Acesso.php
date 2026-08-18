<?php

namespace Baja\Certificado\Insercao;

use Baja\Model\User;
use Baja\Session;

/**
 * The gate on every certificate insertion page.
 *
 * `certificados` is a global permission, not an event-scoped one, and it is
 * not implied by anything else except `admin`. Being able to reach /juiz says
 * a person judges an event; it does not say they may assert that somebody
 * participated in one.
 *
 * One function so that adding a page cannot mean adding a subtly different
 * check. Callers use the returned user rather than asking Session again — it
 * is the same object, and having it in hand is what puts the operator's name
 * in the page header.
 */
final class Acesso
{
    public const PERMISSAO = 'certificados';

    public static function exigir(): User
    {
        // Resolves the phpBB session and redirects to login if there is none,
        // exactly as every other juiz page does. A user authenticated at the
        // forum but with no row here lands on the unprovisioned page.
        $usuario = Session::getCurrentUser();

        if (!Session::hasPermission(self::PERMISSAO)) {
            Template::negarAcesso($usuario);
        }

        return $usuario;
    }

    /** Whether to render the link to these pages at all. */
    public static function permitido(): bool
    {
        return Session::hasPermission(self::PERMISSAO);
    }
}
