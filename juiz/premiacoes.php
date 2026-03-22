<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\PremiacaoQuery;
use Baja\Session;

Session::permissionCheck('PREMIACAO');

$currentEvent = EventoQuery::getCurrentEvent();
$isAdmin = Session::getCurrentUser()->hasPermission('admin');

$query = PremiacaoQuery::create()->filterByEventoId($currentEvent->getEventoId());
if (!$isAdmin) {
    $query->filterByStatus(true);
}
$premiacoes = $query->find();

Template::printHeader('Premiações', false);
?>
<div style="max-width: 900px; margin: 0 auto;">
    <table id="premiacoesTable" class="tablesorter">
        <thead>
        <tr style="height: 50px;">
            <th colspan="4" class="sorter-false" style="font-size: 24px; line-height: 28px;">
                <span style="float:left;"><a href="index.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                Premiações
            </th>
        </tr>
        <tr>
            <th>ID</th>
            <th>Nome</th>
            <th>Status</th>
            <th class="sorter-false">Ação</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!count($premiacoes)) { ?>
            <tr>
                <td colspan="4">Nenhuma premiação configurada para este evento.</td>
            </tr>
        <?php } else { ?>
            <?php foreach ($premiacoes as $p) { ?>
                <tr>
                    <td><?= htmlspecialchars($p->getPremiacaoId()) ?></td>
                    <td style="text-align: left;"><?= htmlspecialchars($p->getNome()) ?></td>
                    <td><?= $p->getStatus() ? 'Ativa' : 'Inativa' ?></td>
                    <td><a href="premiacao_view.php?id=<?= urlencode($p->getPremiacaoId()) ?>">Abrir</a></td>
                </tr>
            <?php } ?>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php
Template::printFooter();
