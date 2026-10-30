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
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;

// The event's people at a glance: who enters scores on which prova. Read
// only since BAJAAPP-76; roles are given and taken on admin_usuario.php,
// through RoleGranting. For whoever may give roles on the whole event.
Authorization::require(Permission::ROLE_GRANT, Authorization::currentEvent());

// Creating a user only makes the row: refused without the form's token (Baja\Auth\Csrf).
if (isset($_REQUEST['act'])) Csrf::enforce();

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$provas = ProvaQuery::create()->filterByEventoId($evento)->find();

if (@$_REQUEST['act'] == 'create') {
    // The form's required attribute is client-side only. An empty username
    // here would create a row that every anonymous visitor matches in
    // Session::initSession, since the shim represents no-session as
    // username => ''. Enforce it server-side too.
    $novoUsername = trim((string) ($_POST['username'] ?? ''));
    if ($novoUsername === '') {
        header("Location: admin_users.php");
        exit();
    }

    $user = UserQuery::create()->findOneByUsername($novoUsername);
    if (!$user) {
        $user = new User();
        $user->setUsername($novoUsername);
        $user->save();
    }
    header("Location: admin_usuario.php?id=" . $user->getUserId()); exit;
}

// Everyone holding something in this event (or above it).
$users = array_filter(
    iterator_to_array(UserQuery::create()->orderByUsername()->find()),
    fn($u) => Authorization::hasAnyGrantInEvent($u, $evento)
);

Template::printHeader("Admin");

?>

<div style="margin: 0 auto;height:100vh;">
    <table id="myTable" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="<?= 1 + count($provas) ?>" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Usuários (<?= Html::e($evento) ?>)</span>
                </th>
            </tr>
            <tr>
                <th class="sorter-false">Username</th>
<?php foreach ($provas as $p){?>
                <th class="sorter-false" style="width: 20px; height: 60px;"><div style="height:20px; transform: rotate(-90deg);"><?= Html::e(strtoupper($p->getProvaId())) ?></div></th>
<?php } ?>
            </tr>
        </thead>
        <tbody>
<?php foreach ($users as $u) { ?>
            <tr>
                <td><a href='admin_usuario.php?id=<?= Html::e($u->getUserId()) ?>'><?= Html::e($u->getUsername()) ?></a></td>
        
<?php foreach ($provas as $p) { ?>
                <td><?= Authorization::can($u, Permission::PROVA_ENTER_SCORES, ScopeNode::prova($evento, (string) $p->getProvaId())) ? "J" : "" ?></td>

<?php } } ?>
            </tr>
        </tbody>
        <tfoot>
            <form action="admin_users.php?act=create" method="POST">
                <?= Csrf::field() ?>
                <tr>
                    <td colspan="<?= 1 + count($provas) ?>" style="text-align: left;">
                        Username:
                        <input type="text" name="username" id="username" style="width: 50%;">
                        <span style="font-size: 12px; color: #666;">abre a página de papéis do usuário</span>
                    </td>
                </tr>
                <tr>
                    <th colspan="<?= 1 + count($provas) ?>" style="height: 30px">
                        <input type="submit" id="userSubmit" value="Papéis do usuário" disabled />
                    </th>
                </tr>
            </form>
        </tfoot>
    </table>
    <br /><br />
</div>
<script type="text/javascript">
    $('input').on('input',function(e){
        $("#userSubmit").prop('disabled', $('#username').val() == "")
        $("#pushSubmit").prop('disabled', $('#heading').val() == "" || $('#msg').val() == "" || $('#filter').val() == "")
    });
</script>

<?php
Template::printFooter();