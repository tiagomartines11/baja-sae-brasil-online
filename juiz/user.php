<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\LogQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\UserQuery;
use DateTimeZone;
use Propel\Runtime\ActiveQuery\Criteria;

if (!isset($_REQUEST['id'])) header("Location: admin.php");

$_user_id = $_REQUEST['id'];

Session::permissionCheck('admin');

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$user = UserQuery::create()->findOneByUserId($_user_id);
if (!$user) header("Location: admin.php");

if (@$_REQUEST['act'] == 'save') {
    if (count((array)@$_POST['j']) > 1) {
        $user->setPermissions((array)@$_POST['j']);
        $user->save();
    } else {
        $user->delete();
    }
    header("Location: user.php?id=".$_user_id);
}

$provas = ProvaQuery::create()->filterByEventoId($currentEventId)->find();
$logs = LogQuery::create()->filterByUser($user->getUsername())->orderById(Criteria::DESC)->find();

Template::printHeader("Detalhes de Usuário", false);

?>
    <div style="max-width: 600px; margin: 0 auto;">
        <form action="user.php?id=<?php echo $user->getUserId();?>&amp;act=save" method="POST">
            <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
                <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?php echo $user->getUsername(); ?></span> <br />
                    </th>
                </thead>
                <tbody>
                <?php
                echo '<tr>
                    <td>Admin:</td>
                    <td>
                        <input type="checkbox" name="j[]" value="admin" '.(array_search("admin", $user->getPermissions()) === false ? "" : "checked").'> J &emsp;
                        <input type="hidden" name="j[]" value="index">
                    </td>
                </tr>';
                foreach ($provas as $p) {
                    echo '<tr>
                            <td>'.strtoupper($p->getProvaId()).':</td>
                            <td>
                                <input type="checkbox" name="j[]" value="'.$p->getFullCode().'" '.(array_search($p->getFullCode(), $user->getPermissions()) === false ? "" : "checked").'> J &emsp; 
                            </td>
                        </tr>';
                }
                ?>
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="2" style="height: 30px">
                        <input type="submit" name="submit" value="Salvar" />
                    </th>
                </tr>
                </tfoot>
            </table>
        </form>
        <br />
        <table id="myTable2" class="tablesorter">
            <thead>
            <tr style="height: 50px">
                <th colspan="3" class="sorter-false">Log de atividades</th>
            </tr>
            <tr>
                <th>Quando</th>
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
            <td>'.$l->getData()->setTimezone(new DateTimeZone("America/Sao_Paulo"))->format('Y-m-d H:i:s').'</td>
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