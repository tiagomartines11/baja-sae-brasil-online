<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;

if (Session::getCurrentUser() && @$_REQUEST['act'] != 'change_pass') {
    header("Location: index.php");
}

if (@$_REQUEST['act'] == 'logout') {
    Session::endSession();
}

if (@$_REQUEST['act'] == 'login') {
    if (Session::setSession($_POST['username'], $_POST['password']))
        header("Location: index.php");
    else
        $msg = "Credenciais inválidas!";
}

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

echo '<form action="login.php?act=login" method="post">
        <span style="color: red">'.(isset($msg) ? $msg . '<br /><br />' : '').'</span>
        <label for="username">Username</label><br />
        <input type="text" id="username" name="username" size="30" />
        <br /><br />
        <label for="password">Senha</label><br />
        <input type="password" id="password" name="password" size="30" />
        <br /><br />
        <input type="submit" value="Entrar"/>
        <br /><br /><br />
        <a href="https://forum.bajasaebrasil.online/ucp.php?mode=sendpassword">Esqueci minha senha</a>
    </form>';

echo '<br /></td></tr></table></div>';

Template::printFooter();

