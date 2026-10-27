<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope;

/**
 * The kinds of place a role can be given on (BAJAAPP-12), from the top:
 *
 *     platform            the whole site; there is exactly one
 *       programa          a competition program: BAJA, AERO, AERO_FORUM...
 *                         (a program can sit under another one)
 *         evento          one event: 26BR
 *           prova         one prova of an event: 26BR/END
 *           fila          one queue of an event: 26BR/3
 *
 * The value is what user_role.scope_type stores. Adding a kind later means
 * a new case here and a ParentFinder for it in ScopeTree; grants already
 * stored never change.
 */
enum ScopeType: string
{
    case PLATFORM = 'platform';
    case PROGRAMA = 'programa';
    case EVENTO = 'evento';
    case PROVA = 'prova';
    case FILA = 'fila';

    /** The kinds that hang from one event: a grant on them expires after it (see GrantExpiry). */
    public function isInsideAnEvent(): bool
    {
        return $this === self::EVENTO || $this === self::PROVA || $this === self::FILA;
    }
}
