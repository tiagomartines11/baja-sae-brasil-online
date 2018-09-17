<?php

if (!isset($_REQUEST['id'])) header("Location: admin.php");

$_prova_id = $_REQUEST['id'];
Session::permissionCheck('admin');

$prova = Prova::getProvaById($_prova_id);
if (!$prova) header("Location: admn.php");

if (@$_REQUEST['act'] == 'toggle') {
        $prova->setStatus($prova->getStatus() == "Parcial" ? "Final" : "Parcial");
        Prova::insertUpdate($prova);
        if ($prova->getStatus() == 'Final') OneSignalClient::sendMessage($prova->getNome(), "Prova finalizada! Confira os resultados!!", "index.php?pg=".$prova->getProvaId());
        header("Location: prova.php?id=".$_prova_id);
}

$logs = Log::getAllForPage($prova->getProvaId());

Template::printHeader("Detalhes de Prova", false);

?>
    <div style="max-width: 600px; margin: 0 auto;">
        <form action="prova.php?act=toggle&amp;id=<?php echo $prova->getProvaId(); ?>" method="POST">
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
                <th style="height: 30px;" colspan="2"><input type="submit" value="Trocar Status" /></th>
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
            <td>'.$l->getData().'</td>
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