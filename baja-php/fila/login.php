<?php
namespace Baja\Fila;

use Baja\Auth\LoginCsrf;
use Baja\Session;
use Baja\Url;

// Logout is handled FIRST, before the already-logged-in redirect below.
//
// Every user who clicks "Logout" is by definition logged in, so the redirect
// below matches them too. It used to fall through into this branch by accident
// — it set a Location header but did not exit, and endSession() then replaced
// that header with its own. Adding the exit() (needed so the warm-up bounce
// cannot overwrite it) turned that accident into a bug: the redirect swallowed
// act=logout and logging out silently bounced the user back to index.php,
// still logged in. Ordering makes it explicit instead of accidental.
if (@$_REQUEST['act'] == 'logout') {
    Session::endSession();
}

if (Session::getCurrentUser() && @$_REQUEST['act'] != 'change_pass') {
    header("Location: index.php");
    exit();
}

// Must run before any output: setcookie() is a no-op once headers are sent,
// and a form whose token has no matching cookie is refused as a forgery.
LoginCsrf::start();

$errorMessages = [
    'missing'           => 'Preencha usuário e senha',
    // One message for both "no such user" and "wrong password". Telling
    // them apart lets an attacker enumerate valid usernames without ever
    // guessing a password — and the lockout is still reachable, so an
    // enumerated name can then be locked out in three requests. The
    // controller collapses the two statuses to one code, so this map has
    // nothing to distinguish even if someone wanted to.
    'bad_credentials'   => 'Usuário ou senha incorretos',
    // NOT "try again in a few minutes" — that was false. phpBB gates
    // $auth->login() behind a CAPTCHA once user_login_attempts hits
    // max_login_attempts, and that counter has no time-based expiry: it is
    // cleared only by a successful login or a password reset. This form cannot
    // present the CAPTCHA, so waiting never helps and the correct password is
    // refused indefinitely. The forum's own login form can show it, and
    // succeeding there resets the counter — so that is the actual way out.
    'too_many_attempts' => 'Muitas tentativas de login. Por segurança o fórum bloqueou sua conta, e só um login no fórum (com verificação de imagem) desbloqueia — esperar não resolve. <a href="'
        . htmlspecialchars(Url::forum('/ucp.php?mode=login'), ENT_QUOTES, 'UTF-8')
        . '">Entrar pelo fórum</a>',
    // The form was submitted from somewhere we don't serve it from, or
    // without the double-submit token. Almost always a stale form left open
    // across a browser restart; reloading mints a fresh token.
    'csrf'              => 'Sessão de login expirada. Recarregue a página e tente novamente.',
    'unknown'           => 'Erro de autenticação',
];
if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])) {
    $msg = $errorMessages[$_GET['error']];
}

$loginRedirect = Url::subdomain('fila', '/index.php');
// redirect rides in the query string rather than a hidden POST field. It was
// put there on the theory that a Cloudflare challenge replayed the POST as a
// bodyless GET; production logs later showed the POST arriving intact, so that
// reason was wrong. Kept because it is the better place for it regardless: the
// target is not user input, it belongs to the URL rather than the form body,
// and a browser preserves an action's query string on a POST.
$loginAction   = Url::forum('/app.php/baja/login?redirect=' . urlencode($loginRedirect));

Template::printHeader("Login", false);

echo '
<div style="max-width:400px; margin: 0 auto">
    <table class="tablesorter">
        <thead>
            <tr class="tablesorter-ignoreRow"> 
                <th class="sorter-false">
                    <span style="float:left; width:30%; ; text-align:left; line-height:40px">
					    <img src="img/baja_grande.png" class="logo">
                    </span>
                    <span style="float:right; height:30%; text-align:right">
                        <img src="img/sae.png" class="logo" width="200px">
                    </span>
                </th>
            </tr>	
            <tr class="tablesorter-ignoreRow" style="height: 40px">
                <th class="sorter-false" style="line-height: 22px;">Sistema de Filas Eletrônicas</th>
            </tr>
        </thead>
<tr>
<td>
<br /><br /> ';

echo '<form action="'.htmlspecialchars($loginAction, ENT_QUOTES, 'UTF-8').'" method="post">
        '.LoginCsrf::field().'
        <span style="color: red">'.(isset($msg) ? $msg . '<br /><br />' : '').'</span>
        <label for="username">Username</label><br />
        <input type="text" id="username" name="username" size="30" />
        <br /><br />
        <label for="password">Senha</label><br />
        <input type="password" id="password" name="password" size="30" />
        <br /><br />
        <input type="submit" value="Entrar"/>
        <br /><br /><br />
        <a href="'.htmlspecialchars(Url::forum('/ucp.php?mode=sendpassword'), ENT_QUOTES, 'UTF-8').'">Esqueci minha senha</a>
    </form>';

echo '<br /></td></tr></table></div>';

Template::printFooter();

