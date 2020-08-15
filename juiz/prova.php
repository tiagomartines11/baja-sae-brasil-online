<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\LogQuery;
use Baja\Model\ProvaQuery;
use Baja\Site\OneSignalClient;
use DateTimeZone;

if (!isset($_REQUEST['id'])) header("Location: index.php");

$_page = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) header("Location: index.php");

if (@$_REQUEST['act'] == 'Trocar Status') {
    $prova->setStatus($prova->getStatus() == "Parcial" ? "Final" : "Parcial");
    $prova->save();
    if ($prova->getStatus() == 'Final') OneSignalClient::sendMessage($prova->getNome(), "Prova finalizada! Confira os resultados!!", "index.php?pg=".$prova->getProvaId());
    header("Location: prova.php?id=".$_page);
}

if (@$_REQUEST['act'] == 'Refresh Pontos') {
    $prova->refreshVarsAndPontos();
    header("Location: prova.php?id=".$_page);
}

$logs = LogQuery::create()->filterByPagina($prova->getFullCode())->find();

Template::printHeader("Detalhes de Prova", false);

?>
    <div style="max-width: 600px; margin: 0 auto;">
        <form action="prova.php?id=<?php echo $prova->getProvaId(); ?>" method="POST">
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
            <tr style="height: 50px">
                <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                    <span style="float:left"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;"><?php echo $prova->getNome(); ?></span> <br />
                </th>
            </thead>
            <tbody>
            <tr>
                <td>Status Atual:</td>
                <td><?php echo $prova->getStatus(); ?></td>
            </tr>
            </tbody>
            <tfoot>
            <tr>
                <th style="height: 30px;" colspan="2"><input type="submit" name="act" value="Trocar Status" /> <input type="submit" name="act" value="Refresh Pontos" /></th>
            </tr>
            </tfoot>
        </table>
        </form>
        <br />
        <table id="myTable2" class="tablesorter">
            <thead>
            <tr style="height: 50px">
                <th colspan="4" class="sorter-false">Log de atividades</th>
            </tr>
            <tr>
                <th>Quando</th>
                <th>Quem</th>
                <th>Equipe</th>
                <th class="sorter-false">Dados</th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($logs as $l) {
                $dados = json_decode($l->getDados());
                $dadosStr = '<table style="padding: 0; margin: 0; border: 0; border-collapse: collapse; background-color: transparent; text-align: left;">';
                foreach ($dados as $k=>$v) {
                    $dadosStr .= "<tr><td style='border: 0; background-color: transparent;'>".strtolower($k).":</td><td style='border: 0; background-color: transparent;'>$v</td></tr>";
                }
                $dadosStr .= '</table>';
                echo '<tr>
            <td>'.$l->getData()->setTimezone(new DateTimeZone("Etc/GMT+3"))->format('Y-m-d H:i:s').'</td>
            <td>'.$l->getUser().'</td>
            <td>'.$l->getEquipe().'</td>
            <td>'.$dadosStr.'</td>
            
        </tr>';
            }
            ?>
            </tbody>
        </table>
    </div>


<?php
Template::printFooter();