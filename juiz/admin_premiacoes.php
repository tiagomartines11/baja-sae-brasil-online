<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\PremiacaoQuery;
use Baja\Session;

Session::permissionCheck("admin");

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$premiacoes = PremiacaoQuery::create()->filterByEventoId($evento)->find();

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="3" class="sorter-false">
                    <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Premiações (<?= $evento ?>)</span>
                </th>
            </tr>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Código</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($premiacoes as $p) { ?>
            <tr>
                <td><?= htmlspecialchars($p->getPremiacaoId()) ?></td>
                <td><?= htmlspecialchars($p->getNome()) ?></td>
                <td><a href='premiacao_admin.php?id=<?= urlencode($p->getPremiacaoId()) ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
            </tr>
<?php } ?>

        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="height: 30px">
                    <input type="button" onclick="location.href='premiacao_admin.php?nova=true';" value="Nova Premiação"/>
                </th>
            </tr>
        </tfoot>
    </table>

</div>

<?php
Template::printFooter();
