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
$users = UserQuery::create()->find();
$provas = ProvaQuery::create()->filterByEventoId($evento)->find();

if (@$_REQUEST['act'] == 'chstatus' && isset($_REQUEST['prova']) ) {
    $prova = ProvaQuery::create()->filterByEventoId($evento)->findOneByProvaId(@$_REQUEST['prova']);
    $prova->setStatus($prova->getStatus() == "Parcial" ? "Final" : "Parcial");
    $prova->save();
    //if ($prova->getStatus() == 'Final') OneSignalClient::sendMessage($prova->getNome(), "Prova finalizada! Confira os resultados!!", "index.php?pg=".$prova->getProvaId());
    header("Location: admin_provas.php");
}

if (@$_REQUEST['act'] == 'spoilers') {
    $e = EventoQuery::getCurrentEvent();
    $e->setSpoilers(!$e->getSpoilers());
    $e->save();
    header("Location: admin_provas.php");
}

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="5" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
                    <span style="font-size: 28px;">Provas (<?= $evento ?>)</span>
                </th>
            </tr>
            <tr> 
                <th>Código</th>
                <th>Prova</th>
                <th>Status</th>
                <th>Código</th>
                <th>Log</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($provas as $p) { ?>
            <tr>
                <td><?= $p->getProvaId() ?></td>
                <td><?= $p->getNome() ?></td>
                <td><a href='admin_provas.php?act=chstatus&prova=<?= $p->getProvaId() ?>'><?= $p->getStatus() ?></a></td>
                <td><a href='prova.php?id=<?= $p->getProvaId() ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
                <td><a href='prova_log.php?id=<?= $p->getProvaId() ?>'>&nbsp;👁️&nbsp;</a></td>
            </tr>
<?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="height: 30px">
                    <input type="button" onclick="location.href='prova.php?nova=true';" value="Nova Prova"/>
                </th>
            </tr>
        </tfoot>
    </table>

    <br /><br />

    <form action="admin_provas.php?act=spoilers" method="POST">
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
            <tr style="height: 50px">
                <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                    <span style="float:left"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Spoilers</span> <br />
                </th>
            </thead>
            <tbody>
            <tr>
                <td>Proteção de Spoiler:</td>
                <td><?php echo EventoQuery::getCurrentEvent()->getSpoilers() ? "Ligada" : "Desligada"; ?></td>
            </tr>
            <tfoot>
            <tr>
                <th colspan="2" style="height: 30px">
                    <input type="submit" name="submit" id="pushSubmit" value="Toggle" />
                </th>
            </tr>
            </tfoot>
        </table>
    </form>
    
</div>

<?php
Template::printFooter();