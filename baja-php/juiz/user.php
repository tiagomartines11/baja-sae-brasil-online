<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\EventoQuery;
use Baja\Model\LogQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\UserQuery;
use DateTimeZone;
use Propel\Runtime\ActiveQuery\Criteria;
use Baja\Session;
use Baja\Site\Html;

if (!isset($_REQUEST['id'])) { header("Location: admin.php"); exit; }

$_user_id = $_REQUEST['id'];

Session::permissionCheck('admin');

// Saving rewrites (or deletes) the user: refused without the form's token (Baja\Auth\Csrf).
if (isset($_REQUEST['act'])) Csrf::enforce();

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$user = UserQuery::create()->findOneByUserId($_user_id);
if (!$user) { header("Location: admin.php"); exit; }

$provas = ProvaQuery::create()->filterByEventoId($currentEventId)->find();

if (@$_REQUEST['act'] == 'save') {
    // This form only exposes the global admin flag and the provas of the current
    // event, so it must only edit those; other events, PREMIACAO, FILA, etc. stay.
    $scope = ['admin'];
    foreach ($provas as $p) $scope[] = $p->getFullCode();
    $user->setScopedPermissions($scope, (array)@$_POST['j']);

    if ($user->hasNoMeaningfulPermissions()) {
        $user->delete();
        header("Location: admin_users.php"); exit;
    } else {
        $user->save();
        header("Location: user.php?id=".$_user_id); exit;
    }
}
$logs = LogQuery::create()->filterByUser($user->getUsername())->orderById(Criteria::DESC)->find();

Template::printHeader("Detalhes de Usuário", false);

?>
    <div style="max-width: 600px; margin: 0 auto;">
        <form action="user.php?id=<?php echo Html::e($user->getUserId());?>&amp;act=save" method="POST">
            <?= Csrf::field() ?>
            <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
                <thead>
                <tr style="height: 50px">
                    <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                        <span style="float:left"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;"><?php echo Html::e($user->getUsername()); ?></span> <br />
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
                            <td>'.Html::e(strtoupper($p->getProvaId())).':</td>
                            <td>
                                <input type="checkbox" name="j[]" value="'.Html::field($p->getFullCode()).'" '.(array_search($p->getFullCode(), $user->getPermissions()) === false ? "" : "checked").'> J &emsp; 
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
                // What the judge typed: escaped. A list (a multiple choice) is shown as JSON.
                foreach ($dados as $k=>$v) {
                    $v = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
                    $dadosStr .= "<tr><td style='border: 0; background-color: transparent;'>".Html::e(strtolower($k)).":</td><td style='border: 0; background-color: transparent;'>".Html::e($v)."</td></tr>";
                }
                $dadosStr .= '</table>';
                echo '<tr>
            <td>'.$l->getData()->setTimezone(new DateTimeZone("Etc/GMT+3"))->format('Y-m-d H:i:s').'</td>
            <td>'.Html::e($l->getEquipe()).'</td>
            <td>'.$dadosStr.'</td>
            
        </tr>';
            }
            ?>
            </tbody>
        </table>
    </div>


<?php
Template::printFooter();