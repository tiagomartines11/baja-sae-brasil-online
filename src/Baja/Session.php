<?php
namespace Baja;
use Baja\Model\User;
use Baja\Model\UserQuery;

class Session
{
    /** @var User */
    private static $_currentUser = null;

    static function initSession() {
        global $user;
        Session::$_currentUser = UserQuery::create()->findOneByUsername($user->data["username"]);
        if (!Session::$_currentUser) {
            if ($_SERVER["SCRIPT_NAME"] != "/login.php") {
                header("Location: login.php");
                exit();
            }
        }
    }

    /**
     * @param string $username
     * @param string $password
     * @return bool
     */
    static function setSession($username, $password) {
        global $auth, $user;
        /** @noinspection PhpUndefinedMethodInspection */
        $result = $auth->login($username, $password, true, 1, 0);
        if ($result['status'] == LOGIN_SUCCESS) {
            Session::$_currentUser = UserQuery::create()->findOneByUsername($user->data["username"]);
            if (Session::$_currentUser) return true;
        }
        return false;
    }

    static function setForcedSession($username) {
        Session::$_currentUser = UserQuery::create()->findOneByUsername($username);
    }

    static function endSession() {
        // Cookie clearing has to happen on the domain that set the cookies —
        // forum.baja.local — so we bounce through the forum's logout
        // endpoint. After phpBB clears its session row + cookies, it
        // redirects the browser back to our login page, which lands
        // anonymous on the next request.
        Session::$_currentUser = null;
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? Url::scheme();
        $host   = $_SERVER['HTTP_HOST']      ?? Url::domain();
        $loginUrl = $scheme . '://' . $host . '/login.php';
        $logoutUrl = Url::forum('/app.php/baja/logout?redirect=' . urlencode($loginUrl));
        header("Location: $logoutUrl");
        exit();
    }

    /**
     * Gets the User object for the user that's is currently logged in.
     * @return User
     */
    public static function getCurrentUser()
    {
        if (!Session::$_currentUser) Session::initSession();
        return Session::$_currentUser;
    }

    /**
     * This function should be called on every page to prevent access
     * to specific modules/actions based on the users access level.
     * @param string $permissionCode
     */
    public static function permissionCheck($permissionCode)
    {
        if (!self::hasPermission($permissionCode)) {
            die("Você não tem acesso a essa página");
        }
    }

    /**
     * @param string $permissionCode
     * @return bool
     */
    public static function hasPermission($permissionCode) {
        if ($permissionCode != "index") $permissionCode = $_SERVER['REDIRECT_EVENT']."_".$permissionCode;
        return self::getCurrentUser()->hasPermission($permissionCode) || self::getCurrentUser()->hasPermission('admin');
    }
}