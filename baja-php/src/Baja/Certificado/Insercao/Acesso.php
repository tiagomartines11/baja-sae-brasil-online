<?php

namespace Baja\Certificado\Insercao;

use Baja\Model\User;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Session;

/**
 * The gate on every certificate insertion page.
 *
 * certificado.manage on EVERY top-level program (Authorization::canEverywhere):
 * these pages work across events (one form takes any event, one batch may
 * mix them, the search covers all), so until they ask per event they are
 * for someone whose permission covers every event. A platform admin, or a
 * certificate_manager on each program: what the legacy global code
 * `certificados` became. Being a judge says nothing here: it does not say
 * a person may assert that somebody participated in an event.
 *
 * One function so that adding a page cannot mean adding a subtly different
 * check. Callers use the returned user rather than asking Session again — it
 * is the same object, and having it in hand is what puts the operator's name
 * in the page header.
 */
final class Acesso
{
    public const PERMISSAO = Permission::CERTIFICADO_MANAGE;

    public static function exigir(): User
    {
        // Resolves the phpBB session and redirects to login if there is none,
        // exactly as every other juiz page does. A user authenticated at the
        // forum but with no row here lands on the unprovisioned page.
        $usuario = Session::getCurrentUser();

        if (!self::usuarioPode($usuario)) {
            Template::negarAcesso($usuario);
        }

        return $usuario;
    }

    /** Whether to render the link to these pages at all. */
    public static function permitido(): bool
    {
        return self::usuarioPode(Session::getCurrentUser());
    }

    /** The rule itself, for any user: also who gets the reports (Requerimento\Destinatarios). */
    public static function usuarioPode(?User $usuario): bool
    {
        return Authorization::canEverywhere($usuario, self::PERMISSAO);
    }
}
