<?php

use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Insercao\Csrf;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Session;

T::group('acesso');

// --- CSRF ---------------------------------------------------------------------
//
// No PHP session exists here, so the token is derived from the phpBB session
// id. These run without a database.

$cookieAntes = $_COOKIE;
putenv('PHPBB_COOKIE_PREFIX=zztest');

$_COOKIE = [];
T::same('', Csrf::token('formulario'), 'no session means no token');
T::ok('and nothing validates against it', !Csrf::valido('formulario', ''));
T::ok('not even a plausible-looking value', !Csrf::valido('formulario', str_repeat('a', 64)));

$_COOKIE = ['zztest_sid' => 'sessao-de-teste-1'];
$token = Csrf::token('formulario');
T::same(64, strlen($token), 'a token is a sha256 hex digest');
T::ok('a token validates against itself', Csrf::valido('formulario', $token));
T::ok('and not against a different form', !Csrf::valido('outro-formulario', $token));
T::notSame($token, Csrf::token('outro-formulario'), 'each form gets its own token');

$_COOKIE = ['zztest_sid' => 'sessao-de-teste-2'];
T::ok('a token from another session does not validate', !Csrf::valido('formulario', $token));
T::notSame($token, Csrf::token('formulario'), 'and the other session gets its own');

$_COOKIE = ['zztest_sid' => 'sessao-de-teste-1'];
T::ok('the original session still validates', Csrf::valido('formulario', $token));
T::ok('a truncated token is refused', !Csrf::valido('formulario', substr($token, 0, 32)));
T::ok('an empty token is refused', !Csrf::valido('formulario', ''));
T::ok('a null token is refused', !Csrf::valido('formulario', null));

$campo = Csrf::campo('formulario');
T::ok('the hidden field carries the token', str_contains($campo, $token));
T::ok('under the expected name', str_contains($campo, 'name="' . Csrf::CAMPO . '"'));

$_COOKIE = $cookieAntes;

// --- the permission -------------------------------------------------------------

if (!test_db_available()) {
    T::skip('permission tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventoAmbiente = $_SERVER['REDIRECT_EVENT'] ?? null;
$_SERVER['REDIRECT_EVENT'] = '26BR';

$comPermissoes = static function (array $permissoes) {
    $username = 'ZZFixtureAcesso';
    $user = UserQuery::create()->findOneByUsername($username) ?? new User();
    $user->setUsername($username);
    $user->setPermissions($permissoes);
    $user->save();
    Session::setForcedSession($username);
};

$comPermissoes(['index']);
T::ok('a plain juiz user does not hold certificados', !Session::hasPermission('certificados'));
T::ok('and the link is not rendered for them', !Acesso::permitido());

$comPermissoes(['index', 'certificados']);
T::ok('a user granted certificados holds it', Session::hasPermission('certificados'));
T::ok('and the link is rendered for them', Acesso::permitido());

// The permission is global. An event-scoped grant is not it, and must not be
// mistaken for it — the pages are cross-event, and the event a request lands
// on is inferred rather than chosen.
$comPermissoes(['index', '26BR_certificados']);
T::ok('an event-scoped grant does not open the pages', !Session::hasPermission('certificados'));

// Nor does it change when the ambient event does, which is the failure the
// global list exists to prevent.
$comPermissoes(['index', 'certificados']);
$_SERVER['REDIRECT_EVENT'] = '23SE';
T::ok('the grant survives a change of ambient event', Session::hasPermission('certificados'));
$_SERVER['REDIRECT_EVENT'] = '26BR';

// Being a judge is not being a certificate issuer.
$comPermissoes(['index', '26BR_PREMIACAO', '26BR_ve1']);
T::ok('holding other permissions at an event grants nothing here', !Session::hasPermission('certificados'));

// admin still satisfies everything, as it does everywhere else.
$comPermissoes(['admin']);
T::ok('admin holds it', Session::hasPermission('certificados'));

// And the permission does not leak the other way: holding certificados is not
// admin.
$comPermissoes(['index', 'certificados']);
T::ok('certificados is not admin', !Session::hasPermission('admin'));

UserQuery::create()->filterByUsername('ZZFixtureAcesso')->delete();
Session::setForcedSession('');

if ($eventoAmbiente === null) {
    unset($_SERVER['REDIRECT_EVENT']);
} else {
    $_SERVER['REDIRECT_EVENT'] = $eventoAmbiente;
}
