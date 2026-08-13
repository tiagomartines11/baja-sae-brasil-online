<?php
declare(strict_types=1);

namespace Baja\Auth;

class PhpbbAuthShim
{
    public function __construct(private SessionStore $store) {}

    /**
     * Mirrors phpBB's $auth->login() return shape:
     *   ['status' => LOGIN_SUCCESS,        'error_msg' => '',           'user_row' => [...]]
     *   ['status' => LOGIN_ERROR_USERNAME, 'error_msg' => 'Unknown',    'user_row' => null]
     *   ['status' => LOGIN_ERROR_PASSWORD, 'error_msg' => 'Bad creds',  'user_row' => null]
     *
     * baja's Baja\Session::setSession only checks $result['status'] === LOGIN_SUCCESS.
     *
     * The $autologin / $viewonline / $admin parameters are accepted to match
     * phpBB's signature (Baja\Session passes true, 1, 0) but are not used —
     * the shim doesn't implement persistent autologin or admin-session flags.
     */
    public function login(
        string $username,
        string $password,
        bool   $autologin = false,
        int    $viewonline = 1,
        int    $admin = 0
    ): array {
        $row = $this->store->lookupUserByUsername($username);
        if ($row === null) {
            return ['status' => LOGIN_ERROR_USERNAME, 'error_msg' => 'Unknown user', 'user_row' => null];
        }
        if (!PasswordVerifier::verify($password, $row['user_password'])) {
            return ['status' => LOGIN_ERROR_PASSWORD, 'error_msg' => 'Bad password', 'user_row' => null];
        }
        return ['status' => LOGIN_SUCCESS, 'error_msg' => '', 'user_row' => $row];
    }

    /** No-op — result is never read by baja-app code. */
    public function acl(array $userdata): void {}

    public function __call(string $name, array $args)
    {
        throw new \BadMethodCallException("PhpbbAuthShim does not implement '{$name}'.");
    }
}
