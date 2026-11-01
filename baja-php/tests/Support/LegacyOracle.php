<?php
declare(strict_types=1);

namespace Baja\Tests\Support;

/**
 * What the site answered BEFORE the switch-over to RBAC (BAJAAPP-76), for
 * a user whose legacy codes are $codes.
 *
 * The parity tests ask the new code and this oracle the same question and
 * expect the same answer. Each method is the legacy check, copied from the
 * code as it was before the switch-over (git: 7edfafc, the tip of
 * platform/rbac before this branch), with the user's row replaced by the
 * plain list of codes. Nothing in the application calls this class.
 *
 * Two primitives did all the work:
 *
 *   rowHas($code)               $user->hasPermission($code): is the exact
 *                               code in the list?
 *   sessionHas($event, $code)   Session::hasPermission($code): prefix the
 *                               code with the request's event (except
 *                               'index' and 'certificados'), then: in the
 *                               list, or 'admin' in the list
 */
final class LegacyOracle
{
    /** @param string[] $codes */
    public function __construct(private array $codes)
    {
    }

    // ------------------------------------------------------------------
    // The two primitives
    // ------------------------------------------------------------------

    /** Model\User::hasPermission (generated): exact match in the list. */
    public function rowHas(string $code): bool
    {
        return in_array($code, $this->codes, true);
    }

    /** Session::hasPermission, with $_SERVER['REDIRECT_EVENT'] = $event. */
    public function sessionHas(string $event, string $code): bool
    {
        if (!in_array($code, ['index', 'certificados'], true)) {
            $code = $event . '_' . $code;
        }
        return $this->rowHas($code) || $this->rowHas('admin');
    }

    // ------------------------------------------------------------------
    // The call sites, as they were
    // ------------------------------------------------------------------

    /** juiz/index.php and juiz/contagem.php: Session::permissionCheck('index'). */
    public function startPage(string $event): bool
    {
        return $this->sessionHas($event, 'index');
    }

    /** juiz/index.php prova list, entry.php, save_entry.php, rolling_entry.php, save_rolling_entry.php, tournament_entry.php, dashboard.php: permissionCheck($prova). */
    public function entersScores(string $event, string $prova): bool
    {
        return $this->sessionHas($event, $prova);
    }

    /** juiz/index.php link, premiacoes.php, premiacao_view.php, premiacao_pdf.php: permissionCheck('PREMIACAO'). */
    public function seesPrizes(string $event): bool
    {
        return $this->sessionHas($event, 'PREMIACAO');
    }

    /** premiacoes.php, premiacao_view.php, premiacao_pdf.php: $isAdmin = $user->hasPermission('admin') (sees drafts). */
    public function seesPrizeDrafts(): bool
    {
        return $this->rowHas('admin');
    }

    /** juiz/index.php "Timers" link: hasPermission('TIMER_ADMIN') or any code starting with "{EVT}_TIMER_". */
    public function seesTimersLink(string $event): bool
    {
        if ($this->sessionHas($event, 'TIMER_ADMIN')) {
            return true;
        }
        foreach ($this->codes as $code) {
            if (strpos($code, $event . '_TIMER_') === 0) {
                return true;
            }
        }
        return false;
    }

    /** Every page gated by permissionCheck('admin') / hasPermission('admin'), and index.php's "Administração" link. */
    public function isAdmin(string $event): bool
    {
        return $this->sessionHas($event, 'admin');
    }

    /** admin_timers.php, timer_admin.php, timer_tts.php: permissionCheck('TIMER_ADMIN'). */
    public function managesTimersPage(string $event): bool
    {
        return $this->sessionHas($event, 'TIMER_ADMIN');
    }

    /** Juiz\TimerAcesso::podeConfigurar($eventoId, $user). */
    public function timerPodeConfigurar(string $event): bool
    {
        return $this->rowHas('admin') || $this->rowHas($event . '_TIMER_ADMIN');
    }

    /** Juiz\TimerAcesso::podeUsar($timer, $user). */
    public function timerPodeUsar(string $event, int $timerId): bool
    {
        return $this->timerPodeConfigurar($event) || $this->rowHas($event . '_TIMER_' . $timerId);
    }

    /** Certificado\Insercao\Acesso::exigir / permitido: Session::hasPermission('certificados'). Also Destinatarios. */
    public function certificates(): bool
    {
        return $this->rowHas('certificados') || $this->rowHas('admin');
    }

    /** Auth\StaffCookie::isStaff: 'index' or 'admin' in the row. */
    public function isStaff(): bool
    {
        return $this->rowHas('index') || $this->rowHas('admin');
    }

    /** ContagemAccess::isController: prova in the request's event and hasPermission($prova). */
    public function isController(string $requestEvent, string $provaEvent, string $prova): bool
    {
        return $provaEvent === $requestEvent && $this->sessionHas($requestEvent, $prova);
    }

    /** ContagemAccess::isCounter: hasPermission($prova . '_CONT'). */
    public function isCounter(string $requestEvent, string $provaEvent, string $prova): bool
    {
        return $provaEvent === $requestEvent && $this->sessionHas($requestEvent, $prova . '_CONT');
    }

    /** ContagemAccess::canUsePitLog: controller, or hasPermission($prova . '_BOX'). */
    public function canUsePitLog(string $requestEvent, string $provaEvent, string $prova): bool
    {
        return $this->isController($requestEvent, $provaEvent, $prova)
            || ($provaEvent === $requestEvent && $this->sessionHas($requestEvent, $prova . '_BOX'));
    }

    /** ContagemAccess::mayEnter. */
    public function mayEnter(string $requestEvent, string $provaEvent, string $prova): bool
    {
        return $this->isController($requestEvent, $provaEvent, $prova)
            || $this->isCounter($requestEvent, $provaEvent, $prova)
            || $this->canUsePitLog($requestEvent, $provaEvent, $prova);
    }

    /** ContagemAccess::canCountOn, for a manual source the user is NOT linked to: the controller only. */
    public function canCountOnUnlinkedSource(string $requestEvent, string $provaEvent, string $prova): bool
    {
        return $provaEvent === $requestEvent && $this->sessionHas($requestEvent, $prova);
    }

    /** ContagemAccess::userMayStillLogStops($username, $evento, $prova): admin, controller code or pit code, from the row. */
    public function mayStillLogStops(string $event, string $prova): bool
    {
        return $this->rowHas('admin') || $this->rowHas($event . '_' . $prova) || $this->rowHas($event . '_' . $prova . '_BOX');
    }

    /** contagem_controle.php: $isAdmin = Session::hasPermission('admin') (may wipe a started race). */
    public function mayWipeRace(string $event): bool
    {
        return $this->sessionHas($event, 'admin');
    }

    /** Fila\Fila::checkPermissaoFila, the queue-admin half: $usr->hasPermission("{EVT}_FILA_{n}_ADMIN"). No 'admin' shortcut. */
    public function managesQueue(string $event, int $fila): bool
    {
        return $this->rowHas($event . '_FILA_' . $fila . '_ADMIN');
    }

    /** Fila\Fila::checkPermissaoFila, the team half: $usr->hasPermission("{EVT}_FILA_{n}_{team}"). */
    public function queueForTeam(string $event, int $fila, string $team): bool
    {
        return $this->managesQueue($event, $fila) || $this->rowHas($event . '_FILA_' . $fila . '_' . $team);
    }
}
