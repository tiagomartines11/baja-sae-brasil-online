<?php
declare(strict_types=1);

namespace Baja\Rbac\Permission;

use Baja\Rbac\Scope\ScopeType;

/**
 * Every permission there is (BAJAAPP-12). The value is what
 * role_permission.permission stores.
 *
 * Each permission names the node types it APPLIES TO: the kinds of place
 * where asking about it makes sense. "Enter scores" is about one prova, so
 * asking it about a whole event is a mistake in the calling code and is
 * denied, even for a platform admin. Where a permission is HELD is a
 * different thing: a grant on an event, a program or the platform holds
 * it for every prova below. See Authorizer.
 *
 * Since phase 3 (BAJAAPP-76) every staff page asks these, through
 * Baja\Rbac\Authorization. "Legacy" below says which of the old codes in
 * user.permissions each one replaced; the full table, call site by call
 * site, is in docs/rbac.md.
 */
enum Permission: string
{
    /** At a programa: create its events. At an evento: edit or delete it, clone from it. Legacy: 'admin' on evento.php, admin_eventos.php, clonar_evento.php. */
    case EVENTO_MANAGE = 'evento.manage';

    /** At an evento: add provas. At a prova: its parameters and formulas, its log. Legacy: 'admin' on prova.php, admin_provas.php, prova_log.php, torneio.php. */
    case PROVA_CONFIGURE = 'prova.configure';

    /** Type in and save a prova's scores. Legacy: <EVENT>_<PROVA> on entry.php, save_entry.php, rolling_entry.php, tournament_entry.php, dashboard.php. */
    case PROVA_ENTER_SCORES = 'prova.enter_scores';

    /** An event's resultados (the published tables). Legacy: 'admin' on resultado.php, admin_resultados.php. */
    case RESULTADO_EDIT = 'resultado.edit';

    /** At an evento: create its queues. At a fila: run it, its access list. Legacy: <EVENT>_FILA_<id>_ADMIN, and 'admin' on admin_filas.php, fila.php, admin_fila_acesso.php. */
    case FILA_MANAGE = 'fila.manage';

    /** Create and configure an event's timers. Legacy: <EVENT>_TIMER_ADMIN. */
    case TIMER_MANAGE = 'timer.manage';

    /** Operate an event's timers. Legacy: <EVENT>_TIMER_<id>, which was per timer; timers are not nodes, so this is per event. */
    case TIMER_OPERATE = 'timer.operate';

    /** Insert, review and annul certificates. Legacy: 'certificados', which was global. */
    case CERTIFICADO_MANAGE = 'certificado.manage';

    /** An event's prizes: create and edit them, and see the ones not published yet. Legacy: 'admin' on premiacao_admin.php, admin_premiacoes.php, and on the drafts in premiacoes.php, premiacao_view.php, premiacao_pdf.php. */
    case PREMIACAO_MANAGE = 'premiacao.manage';

    /** See an event's published prizes (the judges' list, the page, the PDF). Legacy: <EVENT>_PREMIACAO on premiacoes.php, premiacao_view.php, premiacao_pdf.php and the start page's link. Added in phase 3: the legacy code let people read the prizes, not manage them. */
    case PREMIACAO_VIEW = 'premiacao.view';

    /** An event's teams. Legacy: 'admin' on admin_equipes.php, equipe.php. */
    case EQUIPE_MANAGE = 'equipe.manage';

    /** Send push notifications about an event. Legacy: 'admin' on admin_push.php. */
    case NOTIFICACAO_SEND = 'notificacao.send';

    /** Count laps of a lap-counted prova. Legacy: <EVENT>_<PROVA>_CONT. */
    case CONTAGEM_COUNT = 'contagem.count';

    /** Log pit stops of a lap-counted prova. Legacy: <EVENT>_<PROVA>_BOX. */
    case CONTAGEM_PIT_LOG = 'contagem.pit_log';

    /** Race control of a lap-counted prova. Legacy: <EVENT>_<PROVA> (the controller) and 'admin' on contagem_controle.php. */
    case CONTAGEM_CONTROL = 'contagem.control';

    /** Give and take away roles here and below (within the limits in RoleGranting). Legacy: 'admin' on admin_usuarios.php, admin_usuario.php, admin_users.php, user.php. */
    case ROLE_GRANT = 'role.grant';

    /** Edit what roles are (their permissions, where they may be given), and give system roles. Platform only. */
    case ROLE_MANAGE = 'role.manage';

    /** @return ScopeType[] the node types this permission can be asked about */
    public function appliesTo(): array
    {
        return match ($this) {
            self::EVENTO_MANAGE, self::CERTIFICADO_MANAGE => [ScopeType::PROGRAMA, ScopeType::EVENTO],
            self::PROVA_CONFIGURE => [ScopeType::EVENTO, ScopeType::PROVA],
            self::FILA_MANAGE => [ScopeType::EVENTO, ScopeType::FILA],
            self::PROVA_ENTER_SCORES, self::CONTAGEM_COUNT, self::CONTAGEM_PIT_LOG, self::CONTAGEM_CONTROL => [ScopeType::PROVA],
            self::RESULTADO_EDIT, self::TIMER_MANAGE, self::TIMER_OPERATE, self::PREMIACAO_MANAGE,
            self::PREMIACAO_VIEW, self::EQUIPE_MANAGE, self::NOTIFICACAO_SEND => [ScopeType::EVENTO],
            self::ROLE_GRANT => ScopeType::cases(),
            self::ROLE_MANAGE => [ScopeType::PLATFORM],
        };
    }

    public function appliesToType(ScopeType $type): bool
    {
        return in_array($type, $this->appliesTo(), true);
    }
}
