<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Session;
use Baja\Site\Html;

// For anyone who may give roles somewhere (role.grant): admin_usuario.php
// offers them only the places they cover.
$atual = Session::getCurrentUser();
if (!Authorization::canAnywhere($atual, Permission::ROLE_GRANT)) {
    die(Authorization::REFUSAL);
}

// Creating a user is a change: refused without the form's token (Baja\Auth\Csrf).
if (isset($_REQUEST['act'])) Csrf::enforce();

if (@$_REQUEST['act'] == 'create') {
    $username = trim($_POST['username'] ?? '');
    if ($username !== '') {
        $user = UserQuery::create()->findOneByUsername($username);
        if (!$user) {
            // Only the row, holding nothing: admin_usuario.php is where roles
            // are given.
            $user = new User();
            $user->setUsername($username);
            $user->save();
        }
        header("Location: admin_usuario.php?id=".$user->getUserId()); exit;
    }
    header("Location: admin_usuarios.php"); exit;
}

$users = UserQuery::create()->orderByUsername()->find();
$authorizer = Authorization::services()->authorizer;

Template::printHeader("Usuários", false, false);

?>

<div style="max-width: 700px; margin: 0 auto; height:100vh;">

    <table id="myTable" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="3" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Usuários</span>
                </th>
            </tr>
            <tr>
                <th colspan="3" class="sorter-false" style="padding: 6px;">
                    <input type="text" id="filter" placeholder="Filtrar usuários..." style="width: 97%; box-sizing: border-box; padding: 6px;" autocomplete="off">
                </th>
            </tr>
            <tr>
                <th>Username</th>
                <th class="sorter-false">Papéis ativos</th>
                <th class="sorter-false">Editar</th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($users as $u) { ?>
            <tr>
                <td class="username"><?= Html::e($u->getUsername()) ?></td>
                <td style="text-align: center;"><?= count($authorizer->activeGrants((int) $u->getUserId())) ?></td>
                <td style="text-align: center;"><a href="admin_usuario.php?id=<?= Html::e($u->getUserId()) ?>"><span>&nbsp;✏️&nbsp;</span></a></td>
            </tr>
<?php } ?>
        </tbody>
        <tfoot>
            <form action="admin_usuarios.php?act=create" method="POST">
            <?= Csrf::field() ?>
            <tr>
                <th colspan="3" style="height: 30px;">
                    Novo usuário:
                    <input type="text" name="username" id="new_username" autocomplete="off" style="padding: 4px; width: 45%;" />
                    <input type="submit" id="add_user" value="Adicionar usuário" disabled />
                </th>
            </tr>
            </form>
<?php if (array_filter(Authorization::nodesGiving($atual, Permission::FILA_MANAGE), fn($n) => $n->type->value !== 'fila') !== []) { ?>
            <tr>
                <th colspan="3" style="height: 30px">
                    <input type="button" onclick="location.href='admin_fila_acesso.php';" value="Acesso em massa às filas" />
                </th>
            </tr>
<?php } ?>
        </tfoot>
    </table>
    <br /><br />
</div>

<script type="text/javascript">
    $('#filter').on('input', function () {
        var q = $(this).val().toLowerCase();
        $('#myTable tbody tr').each(function () {
            var name = $(this).find('.username').text().toLowerCase();
            $(this).toggle(name.indexOf(q) !== -1);
        });
    });
    $('#new_username').on('input', function () {
        $('#add_user').prop('disabled', $.trim($(this).val()) === '');
    });
</script>

<?php
Template::printFooter();
