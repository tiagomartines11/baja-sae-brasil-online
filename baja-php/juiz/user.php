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
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;

if (!isset($_REQUEST['id'])) { header("Location: admin.php"); exit; }

$_user_id = $_REQUEST['id'];

// A user's score entries in this event, and the roles they hold in it.
// Read only since BAJAAPP-76: roles are given on admin_usuario.php.
Authorization::require(Permission::ROLE_GRANT, Authorization::currentEvent());

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$user = UserQuery::create()->findOneByUserId($_user_id);
if (!$user) { header("Location: admin.php"); exit; }

$provas = ProvaQuery::create()->filterByEventoId($currentEventId)->find();

$logs = LogQuery::create()->filterByUser($user->getUsername())->orderById(Criteria::DESC)->find();

Template::printHeader("Detalhes de Usuário", false);

?>
    <div style="max-width: 600px; margin: 0 auto;">
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
                foreach ($provas as $p) {
                    $juiz = Authorization::can($user, Permission::PROVA_ENTER_SCORES, ScopeNode::prova($currentEventId, (string) $p->getProvaId()));
                    echo '<tr>
                            <td>'.Html::e(strtoupper($p->getProvaId())).':</td>
                            <td>'.($juiz ? 'J' : '').'</td>
                        </tr>';
                }
                ?>
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="2" style="height: 30px">
                        <a href="admin_usuario.php?id=<?php echo Html::e($user->getUserId()); ?>" style="color: white;">Papéis do usuário</a>
                    </th>
                </tr>
                </tfoot>
            </table>
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