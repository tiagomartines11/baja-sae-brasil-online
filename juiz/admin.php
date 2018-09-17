<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Site\OneSignalClient;

Session::permissionCheck("admin");

if (@$_REQUEST['act'] == 'create') {
    $user = UserQuery::create()->findOneByUsername($_POST['username']);
    if (!$user) {
        $user = new User();
        $user->setUsername($_POST['username']);
    }
    if (count((array)@$_POST['j']) > 1) {
        $user->setPermissions((array)@$_POST['j']);
        $user->save();
    } else {
        $user->delete();
    }
    header("Location: admin.php");
}

if (@$_REQUEST['act'] == 'push') {
    OneSignalClient::sendMessage(@$_POST['heading'], @$_POST['msg'], "/", @$_POST['filter']);
}

$users = UserQuery::create()->find();
$provas = ProvaQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->find();

Template::printHeader("Admin");

echo '<div style="max-width: 600px; margin: 0 auto;">
<table id="myTable" class="tablesorter">';
echo '<thead>
        <tr style="height: 50px;"><th colspan="'.(1 + count($provas)).'" class="sorter-false">
            <span style="float:left;"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
            <span style="font-size: 28px;">Usuários</span>
        </th></tr>
        <tr> 
        <th class="sorter-false">Username</th>';
foreach ($provas as $p) echo '<th class="sorter-false" style="width: 20px; height: 60px;"><div style="height:20px; transform: rotate(-90deg);">'.strtoupper($p->getProvaId()).'</div></th>';
echo '</tr></thead>';

foreach ($users as $u) {
    echo "<tr>";
    echo "<td><a href='user.php?id={$u->getUserId()}'>{$u->getUsername()}</a></td>";
    foreach ($provas as $p) {
        echo "<td>".(array_search($p->getFullCode(), $u->getPermissions()) === false ? "" : "J")."</td>";
    }
    echo "</tr>";
}
echo '
<tfoot>
<form action="admin.php?act=create" method="POST">
<tr>
<td style="text-align: left;">
    Username:<br />
    <input type="text" name="username" id="username" style="width: 95%;"><br />
    <input type="hidden" name="c[]" value="index"><input type="hidden" name="j[]" value="index">
</td>';
foreach ($provas as $p) {
    echo '<td>
    <input type="checkbox" name="j[]" value="'.$p->getFullCode().'"><br /> J <br />
    </td>';
}
echo '</tr>
<tr><th colspan="'.(1 + count($provas)).'" style="height: 30px"> <input type="submit" id="userSubmit" value="Criar Novo Usuário" disabled /> </th></tr>
</form>
</tfoot>
</table>';

echo '<table id="myTable2" class="tablesorter">';
echo '<thead>
        <tr style="height: 50px;"><th colspan="'.(1 + count($provas)).'" class="sorter-false">
            <span style="float:left;"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span> 
            <span style="font-size: 28px;">Provas</span>
        </th></tr>
        <tr> 
        <th>Código</th>
        <th>Prova</th>
        <th>Status</th>
        </tr></thead>';

foreach ($provas as $p) {
    echo "<tr>
            <td>{$p->getProvaId()}</td>
            <td>{$p->getNome()}</td>
            <td><a href='prova.php?id={$p->getProvaId()}'>{$p->getStatus()}</a></td>
        </tr>";
}
echo '</table>';
?>

    <form action="admin.php?act=push" method="POST">
        <table id="myTable" class="tablesorter" style="margin-bottom: 0;">
            <thead>
            <tr style="height: 50px">
                <th colspan="2" style="vertical-align: middle;" class="sorter-false">
                    <span style="float:left"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Push Notification</span> <br />
                </th>
            </thead>
            <tbody>
            <tr>
                <td>Titulo:</td>
                <td><input type="text" name="heading" id="heading" size="20" value="<?php echo @$_POST['heading']; ?>"></td>
            </tr>
            <tr>
                <td>Mensagem:</td>
                <td><input type="text" name="msg" id="msg" size="20" value="<?php echo @$_POST['msg']; ?>"></td>
            </tr>
            <tr>
                <td>Filtro:</td>
                <td><input type="number" name="filter" id="filter" size="20" min="0" value="<?php echo @$_POST['filter'] ? $_POST['filter'] : 0; ?>"></td>
            </tr>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="2" style="height: 30px">
                    <input type="submit" name="submit" id="pushSubmit" value="Enviar" disabled />
                </th>
            </tr>
            </tfoot>
        </table>
    </form>
    </div>
<script type="text/javascript">
    $('input').on('input',function(e){
        $("#userSubmit").prop('disabled', $('#username').val() == "")
        $("#pushSubmit").prop('disabled', $('#heading').val() == "" || $('#msg').val() == "" || $('#filter').val() == "")
    });
</script>

<?php
Template::printFooter();