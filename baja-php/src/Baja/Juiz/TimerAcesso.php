<?php
namespace Baja\Juiz;

use Baja\Model\Timer;
use Baja\Model\TimerQuery;
use Baja\Model\User;
use Baja\Session;

class TimerAcesso
{
    /**
     * @param string    $eventoId
     * @param User|null $user
     * @return bool
     */
    static function podeConfigurar($eventoId, $user) {
        return $user && ($user->hasPermission('admin') || $user->hasPermission($eventoId . '_TIMER_ADMIN'));
    }

    /**
     * @param Timer     $timer
     * @param User|null $user
     * @return bool
     */
    static function podeUsar($timer, $user) {
        return $user && (self::podeConfigurar($timer->getEventoId(), $user)
            || $user->hasPermission($timer->getEventoId() . '_TIMER_' . $timer->getTimerId()));
    }

    /**
     * Finds the timer a usage request refers to: ?k=<access key> works for
     * anyone holding the key, ?id=<timer_id> needs a session with permission.
     *
     * @return array [Timer|null, string|null who is operating, for the log]
     */
    static function resolver() {
        $user = Session::getCurrentUserOrNull();

        if (isset($_REQUEST['k']) && $_REQUEST['k'] !== '') {
            $timer = TimerQuery::create()->findOneByAccessKey(strtoupper(trim($_REQUEST['k'])));
            return $timer ? [$timer, $user ? $user->getUsername() : 'chave'] : [null, null];
        }

        if (isset($_REQUEST['id']) && $user) {
            $timer = TimerQuery::create()->filterByEventoId($_SERVER['REDIRECT_EVENT'])->findOneByTimerId((int)$_REQUEST['id']);
            if ($timer && self::podeUsar($timer, $user)) return [$timer, $user->getUsername()];
        }

        return [null, null];
    }
}
