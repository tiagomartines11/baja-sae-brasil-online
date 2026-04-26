<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\LogQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

Session::permissionCheck("admin");

if (!isset($_REQUEST['id']) && !isset($_REQUEST['nova'])) header("Location: admin_provas.php");

$_page = $_REQUEST['id'];

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

$logs = LogQuery::create()->filterByPagina($currentEventId.'_'.$_page)->find();

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="5" class="sorter-false">
                    <span style="float:left;"><a href="admin_provas.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
                    <span style="font-size: 28px;">Logs prova <?= $_page ?> (<?= $currentEventId ?>)</span>
                </th>
            </tr>
            <tr> 
                <th>ID Log</th>
                <th>Usuário</th>
                <th>Data</th>
                <th>Equipe</th>
                <th>Dados</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($logs as $log) { ?>
            <tr>
                <td><?= $log->getId() ?></td>
                <td><?= $log->getUser() ?></td>
                <td><?= $log->getData()->format('Y-m-d H:i:s') ?></td>
                <td><?= $log->getEquipe() ?></td>
                <td ><?= json_encode(json_decode($log->getDados()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></td>                
            </tr>
<?php } ?>
        </tbody>        
    </table>

    <br /><br />
    
</div>

<?php
Template::printFooter();