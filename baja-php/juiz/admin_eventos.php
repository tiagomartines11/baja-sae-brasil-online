<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

Session::permissionCheck("admin");

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$users = UserQuery::create()->find();

$eventos = EventoQuery::create()->orderByEventoId('desc')->find();

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="5" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
                    <span style="font-size: 28px;">Eventos</span>
                </th>
            </tr>
            <tr> 
                <th>Código</th>
                <th>Nome</th>
                <th>Tipo</th>
                <th>Ano</th>
                <th>Detalhes</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($eventos as $ev) { ?>
            <tr>
                <td><a href="/<?= $ev->getEventoId() ?>/admin.php"><?= $ev->getEventoId() ?></a></td>
                <td><?= ($ev->isEmAndamento()?'<b>':'') . $ev->getNome() . ($ev->isEmAndamento()?'</b>':'') ?></td>
                <td><?= $ev->getTipo() ?></td>
                <td><?= $ev->getAno() ?></td>
                <td><a href='evento.php?id=<?= $ev->getEventoId() ?>'>&nbsp;✏️&nbsp;</a></td>
            </tr>
<?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="height: 30px">
                    <input type="button" onclick="location.href='evento.php?novo=true';" value="Novo Evento"/>
                </th>
            </tr>
            <tr>
                <th colspan="5" style="height: 30px">
                    <input type="button" onclick="location.href='clonar_evento.php';" value="Clonar Evento"/>
                </th>
            </tr>
        </tfoot>
    </table>
    
</div>

<?php
Template::printFooter();