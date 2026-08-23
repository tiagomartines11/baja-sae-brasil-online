<?php
namespace Baja\Fila;

use Baja\Auth\ChallengeWarmup;
use Baja\Session;
use Baja\Url;

if (Session::getCurrentUser() && @$_REQUEST['act'] != 'change_pass') {
    header("Location: index.php");
    exit();
}

if (@$_REQUEST['act'] == 'logout') {
    Session::endSession();
}

// Must run before any output: may redirect through the forum so the browser
// picks up Cloudflare clearance before it POSTs credentials there.
ChallengeWarmup::ensure('fila');

$errorMessages = [
    'missing'           => 'Preencha usuário e senha',
    'unknown_user'      => 'Usuário desconhecido',
    'bad_password'      => 'Senha incorreta',
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
    'challenge'         => 'A verificação de segurança interrompeu o envio. Entre novamente.',
    'unknown'           => 'Erro de autenticação',
];
if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])) {
    $msg = $errorMessages[$_GET['error']];
}

$loginRedirect = Url::subdomain('fila', '/index.php');
// redirect rides in the query string rather than a hidden POST field so it
// survives a Cloudflare challenge replay, which keeps the URL but discards
// the body. Browsers preserve an action's query string on a POST, so the
// normal path is unaffected.
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
        <span style="color: red">'.(isset($msg) ? $msg . '<br /><br />' : '').'</span>
        <label for="username">Username</label><br />
        <input type="text" id="username" name="username" size="30" />
        <br /><br />
        <label for="password">Senha</label><br />
        <input type="password" id="password" name="password" size="30" />
        <br /><br />
        <input type="submit" value="Entrar"/>
        <br /><br /><br />
        <a href="https://forum.bajasaebrasil.net/ucp.php?mode=sendpassword">Esqueci minha senha</a>
    </form>';

echo '<br /></td></tr></table></div>';

Template::printFooter();

