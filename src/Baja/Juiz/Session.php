<?php
namespace Baja\Juiz;
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

    static function endSession() {
        global $user;
        $user->session_kill();
        $user->session_begin();
        session_unset();
        session_destroy();
        session_write_close();
        setcookie(session_name(), '', 0, '/');
        session_regenerate_id(true);
        header("Location: login.php");
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