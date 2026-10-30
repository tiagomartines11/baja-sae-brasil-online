<?php
namespace Baja\Juiz;

use Baja\Model\Timer;
use Baja\Model\TimerQuery;
use Baja\Model\User;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Session;

class TimerAcesso
{
    /**
     * Create and configure the event's timers: timer.manage on the event.
     *
     * @param string    $eventoId
     * @param User|null $user
     * @return bool
     */
    static function podeConfigurar($eventoId, $user) {
        return Authorization::can($user, Permission::TIMER_MANAGE, ScopeNode::evento((string) $eventoId));
    }

    /**
     * Operate this timer: whoever may configure the event's timers, or holds
     * timer.operate on the event. Timers are not scope nodes, so an operator
     * runs every timer of their event; before RBAC the code named one timer
     * ({EVT}_TIMER_{id}). Decided in docs/rbac.md.
     *
     * @param Timer     $timer
     * @param User|null $user
     * @return bool
     */
    static function podeUsar($timer, $user) {
        return self::podeConfigurar($timer->getEventoId(), $user)
            || Authorization::can($user, Permission::TIMER_OPERATE, ScopeNode::evento((string) $timer->getEventoId()));
    }

    /**
     * Whether the request names its timer by key (?k=) rather than by id with
     * a session. resolver() tries the key first, and so must anything that
     * treats the two paths differently: a key request is authenticated by
     * the key itself, which another site cannot know, so only the session
     * path needs a CSRF token (timer_action.php).
     *
     * @return bool
     */
    static function porChave() {
        return isset($_REQUEST['k']) && $_REQUEST['k'] !== '';
    }

    /**
     * Finds the timer a usage request refers to: ?k=<key> works for anyone
     * holding the access key (full control) or the view key (read only),
     * ?id=<timer_id> needs a session with permission.
     *
     * @return array [Timer|null, string|null who is operating, for the log, bool read only]
     */
    static function resolver() {
        $user = Session::getCurrentUserOrNull();

        if (self::porChave()) {
            $k = strtoupper(trim($_REQUEST['k']));
            $quem = $user ? $user->getUsername() : 'chave';
            if ($timer = TimerQuery::create()->findOneByAccessKey($k)) return [$timer, $quem, false];
            if ($timer = TimerQuery::create()->findOneByViewKey($k)) return [$timer, $quem, true];
            return [null, null, false];
        }

        if (isset($_REQUEST['id']) && $user) {
            $timer = TimerQuery::create()->filterByEventoId($_SERVER['REDIRECT_EVENT'])->findOneByTimerId((int)$_REQUEST['id']);
            if ($timer && self::podeUsar($timer, $user)) return [$timer, $user->getUsername(), false];
        }

        return [null, null, false];
    }
}
