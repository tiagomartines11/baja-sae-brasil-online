<?php
namespace Baja;
use Baja\Auth\LoginCsrf;
use Baja\Auth\NaoProvisionado;
use Baja\Auth\StaffCookie;
use Baja\Model\User;
use Baja\Model\UserQuery;

class Session
{
    /** @var User */
    private static $_currentUser = null;

    static function initSession() {
        global $user;
        $username = (string) ($user->data["username"] ?? '');

        // Never look a user up by the empty string.
        //
        // PhpbbSessionShim represents "no session" as username => '', and
        // findOneByUsername does no empty-value special-casing — it compiles to
        // a plain WHERE username = ''. So if a row with an empty username ever
        // existed, every unauthenticated visitor would match it and be silently
        // logged in as that row, with whatever permissions it carries.
        //
        // Such a row is creatable: Fila::addPermissaoFila and juiz's user admin
        // both do setUsername($input)->save() without checking for empty. Those
        // are guarded now, but this is the seam every request crosses, so it is
        // the one place the invariant has to hold no matter what any writer does
        // later. Anonymous must never match a row.
        if ($username !== '') {
            Session::$_currentUser = UserQuery::create()->findOneByUsername($username);
        }

        if (Session::$_currentUser || $_SERVER["SCRIPT_NAME"] == "/login.php") {
            return;
        }

        // Two different things end up here and they need different answers.
        //
        // No username means no forum session: the login page is the right
        // place to send them, and it is where they were already going.
        //
        // A username with no row here means the forum knows them and this
        // system does not — every new forum member is in that state. Sending
        // them to login made the loop that reads as a broken site: the login
        // succeeds, redirects back, and bounces again, with nothing said at
        // any point. Say it instead.
        if ($username !== '') {
            NaoProvisionado::render($username);
        }

        // Anonymous, so carry any login error code across this bounce. The
        // baja/auth controller appends ?error=<code> to the post-login redirect
        // target — which is index.php, not login.php — so without this the code
        // died here and every failed login landed on a blank form with no
        // explanation at all.
        //
        // Whitelisted to the controller's own code shape so nothing
        // attacker-influenced reaches the Location header. is_string first:
        // ?error[]=x makes this an array, and casting one to string emits an
        // "Array to string conversion" warning before the regex rejects it.
        $error  = $_GET['error'] ?? '';
        $suffix = (is_string($error) && preg_match('/^[a-z_]{1,32}$/', $error) === 1)
            ? '?error=' . urlencode($error)
            : '';
        header("Location: login.php" . $suffix);
        exit();
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
        // Without a session the cookie would only make the judge's browser
        // skip the results cache for nothing. Cleared here, on our own host,
        // because it is ours and not phpBB's; the parent domain covers it.
        StaffCookie::clear();
        // Url::scheme(), not REQUEST_SCHEME: that is nginx's own scheme, which
        // is http behind staging's Caddy even though the visitor is on https.
        $scheme = Url::scheme();
        $host   = $_SERVER['HTTP_HOST'] ?? Url::domain();
        $loginUrl = $scheme . '://' . $host . '/login.php';
        // The csrf token is what distinguishes this from a forged logout —
        // /baja/logout answers to GET, so without it any <img src> on a page
        // the judge visits would log them out mid-event. Separate top-level
        // parameters, so the '&' here is not inside a value phpBB would
        // html-escape.
        $logoutUrl = Url::forum(
            '/app.php/baja/logout?redirect=' . urlencode($loginUrl)
            . '&csrf=' . urlencode(LoginCsrf::token())
        );
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
     * Like getCurrentUser(), but an anonymous visitor gets null instead of a
     * redirect to login. For pages that also work without a session.
     * @return User|null
     */
    public static function getCurrentUserOrNull()
    {
        global $user;
        if (!Session::$_currentUser) {
            $username = (string) ($user->data["username"] ?? '');
            if ($username !== '') Session::$_currentUser = UserQuery::create()->findOneByUsername($username);
        }
        return Session::$_currentUser;
    }

    // Access checks are not here any more. permissionCheck() and
    // hasPermission() read the legacy codes in user.permissions; since
    // BAJAAPP-76 every page asks Baja\Rbac\Authorization instead
    // (docs/rbac.md).
}