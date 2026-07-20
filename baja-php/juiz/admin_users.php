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
$provas = ProvaQuery::create()->filterByEventoId($evento)->find();

if (@$_REQUEST['act'] == 'create') {
    $user = UserQuery::create()->findOneByUsername($_POST['username']);
    $isNew = !$user;
    if ($isNew) {
        $user = new User();
        $user->setUsername($_POST['username']);
    }
    // Only touch the provas of the current event; every other permission the
    // user holds (admin, other events, PREMIACAO, FILA, ...) is preserved.
    $scope = [];
    foreach ($provas as $p) $scope[] = $p->getFullCode();
    $user->setScopedPermissions($scope, (array)@$_POST['j']);

    if ($user->hasNoMeaningfulPermissions()) {
        // Never create an empty user; delete an existing one only when nothing
        // is left across ANY scope, not just this event.
        if (!$isNew) $user->delete();
    } else {
        $user->save();
    }
    header("Location: admin_users.php");
}

$users = UserQuery::create()->find();

Template::printHeader("Admin");

?>

<div style="margin: 0 auto;height:100vh;">
    <table id="myTable" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="<?= 1 + count($provas) ?>" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Usuários (<?= $evento ?>)</span>
                </th>
            </tr>
            <tr>
                <th class="sorter-false">Username</th>
<?php foreach ($provas as $p){?>
                <th class="sorter-false" style="width: 20px; height: 60px;"><div style="height:20px; transform: rotate(-90deg);"><?= strtoupper($p->getProvaId()) ?></div></th>
<?php } ?>
            </tr>
        </thead>
        <tbody>
<?php foreach ($users as $u) { ?>
            <tr>
                <td><a href='user.php?id=<?= $u->getUserId() ?>'><?= $u->getUsername() ?></a></td>
        
<?php foreach ($provas as $p) { ?>
                <td><?= array_search($p->getFullCode(), $u->getPermissions()) === false ? "" : "J" ?></td>

<?php } } ?>
            </tr>
        </tbody>
        <tfoot>
            <form action="admin_users.php?act=create" method="POST">
                <tr>
                    <td style="text-align: left;">
                        Username:<br />
                        <input type="text" name="username" id="username" style="width: 95%;"><br />
                        <input type="hidden" name="c[]" value="index"><input type="hidden" name="j[]" value="index">
                    </td>
<?php foreach ($provas as $p) { ?>
                    <td><input type="checkbox" name="j[]" value="<?= $p->getFullCode() ?>"><br /> J <br /></td>
<?php } ?>
                </tr>
                <tr>
                    <th colspan="<?= 1 + count($provas) ?>" style="height: 30px">
                        <input type="submit" id="userSubmit" value="Atualizar Usuário" disabled />
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