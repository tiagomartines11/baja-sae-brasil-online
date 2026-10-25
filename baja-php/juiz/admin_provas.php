<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;
use Baja\Site\Html;

Session::permissionCheck("admin");

// Both acts below change something: refused without the form's token, before
// anything else. They used to be plain links, so a link or an <img> on any
// other page could flip a prova's status or the event's spoilers.
if (isset($_REQUEST['act'])) Csrf::enforce();

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$users = UserQuery::create()->find();
$provas = ProvaQuery::create()->filterByEventoId($evento)->find();

if (@$_REQUEST['act'] == 'chstatus' && isset($_REQUEST['prova']) ) {
    $prova = ProvaQuery::create()->filterByEventoId($evento)->findOneByProvaId(@$_REQUEST['prova']);
    $prova->setStatus($prova->getStatus() == "Parcial" ? "Final" : "Parcial");
    $prova->save();
    //if ($prova->getStatus() == 'Final') OneSignalClient::sendMessage($prova->getNome(), "Prova finalizada! Confira os resultados!!", "index.php?pg=".$prova->getProvaId());
    header("Location: admin_provas.php"); exit;
}

if (@$_REQUEST['act'] == 'spoilers') {
    $e = EventoQuery::getCurrentEvent();
    $e->setSpoilers(!$e->getSpoilers());
    $e->save();
    header("Location: admin_provas.php"); exit;
}

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="5" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
                    <span style="font-size: 28px;">Provas (<?= Html::e($evento) ?>)</span>
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
                <td><?= Html::e($p->getProvaId()) ?></td>
                <td><?= Html::e($p->getNome()) ?></td>
                <td>
                    <form action="admin_provas.php" method="POST" style="margin: 0;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="act" value="chstatus" />
                        <input type="hidden" name="prova" value="<?= Html::field($p->getProvaId()) ?>" />
                        <button type="submit" style="background: none; border: 0; padding: 0; color: #00e; text-decoration: underline; cursor: pointer; font: inherit;"><?= Html::e($p->getStatus()) ?></button>
                    </form>
                </td>
                <td><a href='prova.php?id=<?= Html::e(rawurlencode($p->getProvaId())) ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
                <td><a href='prova_log.php?id=<?= Html::e(rawurlencode($p->getProvaId())) ?>'>&nbsp;👁️&nbsp;</a></td>
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
        <?= Csrf::field() ?>
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