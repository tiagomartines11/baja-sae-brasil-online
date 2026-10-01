<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\TimerQuery;
use Baja\Session;

Session::permissionCheck("TIMER_ADMIN");

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$timers = TimerQuery::create()->filterByEventoId($evento)->orderByTimerId()->find();
$voltar = Session::getCurrentUser()->hasPermission('admin') ? 'admin.php' : 'timer.php';

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="6" class="sorter-false">
                    <span style="float:left;"><a href="<?= $voltar ?>" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Timers (<?= $evento ?>)</span>
                </th>
            </tr>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Etapas</th>
                <th>Chave de acesso</th>
                <th>Configurar</th>
                <th>Abrir</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($timers as $t) { ?>
            <tr>
                <td><?= $t->getTimerId() ?></td>
                <td><?= htmlspecialchars($t->getNome()) ?></td>
                <td><?= count($t->getConfig()->etapas ?? []) ?></td>
                <td><code><?= htmlspecialchars($t->getAccessKey()) ?></code></td>
                <td><a href='timer_admin.php?id=<?= $t->getTimerId() ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
                <td><a href="timer.php?id=<?= $t->getTimerId() ?>">Abrir</a></td>
            </tr>
<?php } ?>

        </tbody>
        <tfoot>
            <tr>
                <th colspan="6" style="height: 30px">
                    <input type="button" onclick="location.href='timer_admin.php?nova=true';" value="Novo Timer"/>
                </th>
            </tr>
        </tfoot>
    </table>

</div>

<?php
Template::printFooter();
