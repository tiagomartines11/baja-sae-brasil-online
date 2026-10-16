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
     * Finds the timer a usage request refers to: ?k=<key> works for anyone
     * holding the access key (full control) or the view key (read only),
     * ?id=<timer_id> needs a session with permission.
     *
     * @return array [Timer|null, string|null who is operating, for the log, bool read only]
     */
    static function resolver() {
        $user = Session::getCurrentUserOrNull();

        if (isset($_REQUEST['k']) && $_REQUEST['k'] !== '') {
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
