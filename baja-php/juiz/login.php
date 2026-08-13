<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Session;
use Baja\Url;

if (Session::getCurrentUser() && @$_REQUEST['act'] != 'change_pass') {
    header("Location: index.php");
}

if (@$_REQUEST['act'] == 'logout') {
    Session::endSession();
}

$errorMessages = [
    'missing'           => 'Preencha usuário e senha',
    'unknown_user'      => 'Usuário desconhecido',
    'bad_password'      => 'Senha incorreta',
    'too_many_attempts' => 'Muitas tentativas. Tente novamente em alguns minutos',
    'unknown'           => 'Erro de autenticação',
];
if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])) {
    $msg = $errorMessages[$_GET['error']];
}

$loginAction   = Url::forum('/app.php/baja/login');
$loginRedirect = Url::subdomain('juiz', '/index.php');

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
                <th class="sorter-false" style="line-height: 22px;">'.EventoQuery::getCurrentEvent()->getNome().'<br />Entrada de Dados</th>
            </tr>
        </thead>
<tr>
<td>
<br /><br /> ';

echo '<form action="'.htmlspecialchars($loginAction, ENT_QUOTES, 'UTF-8').'" method="post">
        <input type="hidden" name="redirect" value="'.htmlspecialchars($loginRedirect, ENT_QUOTES, 'UTF-8').'">
        <span style="color: red">'.(isset($msg) ? $msg . '<br /><br />' : '').'</span>
        <label for="username">Username</label><br />
        <input type="text" id="username" name="username" size="30" />
        <br /><br />
        <label for="password">Senha</label><br />
        <input type="password" id="password" name="password" size="30" />
        <br /><br />
        <input type="submit" value="Entrar"/>
        <br /><br /><br />
        <a href="'. Url::forum('/ucp.php?mode=sendpassword') . '">Esqueci minha senha</a>
    </form>';

echo '<br /></td></tr></table></div>';

Template::printFooter();

