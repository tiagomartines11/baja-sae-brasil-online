<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\TimerQuery;
use Baja\Session;
use Baja\Site\Html;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;

Authorization::require(Permission::TIMER_MANAGE, Authorization::currentEvent());

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$timers = TimerQuery::create()->filterByEventoId($evento)->orderByTimerId()->find();
$voltar = Authorization::currentUserCan(Permission::EVENTO_MANAGE, Authorization::currentEvent()) ? 'admin.php' : 'timer.php';

Template::printHeader("Admin");

?>

<div style="max-width: 1000px; margin: 0 auto; height:100vh;">

    <table id="myTable2" class="tablesorter">
        <thead>
            <tr style="height: 50px;">
                <th colspan="7" class="sorter-false">
                    <span style="float:left;"><a href="<?= Html::e($voltar) ?>" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                    <span style="font-size: 28px;">Timers (<?= Html::e($evento) ?>)</span>
                </th>
            </tr>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Etapas</th>
                <th>Chave de acesso</th>
                <th>Chave de leitura</th>
                <th>Configurar</th>
                <th>Abrir</th>
            </tr>
        </thead>
        <tbody>

<?php foreach ($timers as $t) { ?>
            <tr>
                <td><?= Html::e($t->getTimerId()) ?></td>
                <td><?= htmlspecialchars($t->getNome()) ?></td>
                <td><?= count($t->getConfig()->etapas ?? []) ?></td>
                <td><code><?= htmlspecialchars($t->getAccessKey()) ?></code></td>
                <td><code><?= htmlspecialchars($t->getViewKey()) ?></code></td>
                <td><a href='timer_admin.php?id=<?= Html::e($t->getTimerId()) ?>'><span>&nbsp;✏️&nbsp;</span></a></td>
                <td><a href="timer.php?id=<?= Html::e($t->getTimerId()) ?>">Abrir</a></td>
            </tr>
<?php } ?>

        </tbody>
        <tfoot>
            <tr>
                <th colspan="7" style="height: 30px">
                    <input type="button" onclick="location.href='timer_admin.php?nova=true';" value="Novo Timer"/>
                </th>
            </tr>
        </tfoot>
    </table>

</div>

<?php
Template::printFooter();
