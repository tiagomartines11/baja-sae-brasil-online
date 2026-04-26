<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

Session::permissionCheck("admin");

$evento = EventoQuery::getCurrentEvent()->getEventoId();

Template::printHeader("Admin");

?>

<div style="max-width: 600px; margin: 0 auto; height:100vh;">

<table id="myTable" class="tablesorter">
    <thead>
        <tr style="height: 50px;">
            <th colspan="2" class="sorter-false">
                <span style="font-size: 28px;">Administração evento (<?= $evento ?>)</span>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr><td>
            <a href="admin_users.php">Administrar usuários</a>
        </td></tr>
        <tr><td>
            <a href="admin_equipes.php">Administrar equipes</a>
        </td></tr>
        <tr><td>
            <a href="admin_provas.php">Administrar provas</a>
        </td></tr>
        <tr><td>
            <a href="admin_resultados.php">Administrar resultados</a>
        </td></tr>
        <tr><td>
            <a href="admin_filas.php">Administrar filas</a>
        </td></tr>
        <tr><td>
            <a href="admin_premiacoes.php">Administrar premiações</a>
        </td></tr>
        <tr><td>
            <a href="admin_push.php">Enviar notificações</a>
        </td></tr>
    </tbody>
</table>

<br/><br/>

<table id="myTable" class="tablesorter">
    <thead>
        <tr style="height: 50px;">
            <th colspan="2" class="sorter-false">
                <span style="font-size: 28px;">Administração geral</span>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr><td>
            <a href="/admin_eventos.php">Administrar eventos</a>
        </td></tr>
    </tbody>
</table>


</div>

<?php
Template::printFooter();