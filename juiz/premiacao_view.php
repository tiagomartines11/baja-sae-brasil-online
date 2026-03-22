<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\PremiacaoQuery;
use Baja\Premiacao\Renderer as PremiacaoRenderer;
use Baja\Premiacao\Resolver as PremiacaoResolver;
use Baja\Session;

Session::permissionCheck('PREMIACAO');

$currentEvent = EventoQuery::getCurrentEvent();
$isAdmin = Session::getCurrentUser()->hasPermission('admin');
$premiacaoId = trim((string)@$_REQUEST['id']);

if ($premiacaoId === '') {
    header('Location: premiacoes.php');
}

$query = PremiacaoQuery::create()
    ->filterByEventoId($currentEvent->getEventoId())
    ->filterByPremiacaoId($premiacaoId);

if (!$isAdmin) {
    $query->filterByStatus(true);
}

$premiacao = $query->findOne();

if (!$premiacao) {
    header('Location: premiacoes.php');
}

$categorias = PremiacaoResolver::resolve($premiacao);
$modificado = $premiacao->getModificado();
$modStr = $modificado ? $modificado->format('d/m/Y H:i') : '';

Template::printHeader(htmlspecialchars($premiacao->getNome()), false);
?>
<div style="max-width: 1100px; margin: 0 auto;">
    <table class="tablesorter" style="margin-bottom: 18px;">
        <thead>
        <tr style="height: 50px;">
            <th colspan="2" class="sorter-false" style="font-size: 24px; line-height: 28px;">
                <span style="float:left;"><a href="premiacoes.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                <?= htmlspecialchars($premiacao->getNome()) ?>
            </th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Evento</td>
            <td><?= htmlspecialchars($currentEvent->getNome()) ?></td>
        </tr>
        <tr>
            <td>Status</td>
            <td><?= $premiacao->getStatus() ? 'Ativa' : 'Inativa' ?></td>
        </tr>
        <tr>
            <td>Atualização</td>
            <td><?= htmlspecialchars($modStr) ?></td>
        </tr>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="2" style="height: 30px;">
                <a href="premiacao_view.php?id=<?= urlencode($premiacao->getPremiacaoId()) ?>" style="color: white;">Atualizar</a>
            </th>
        </tr>
        </tfoot>
    </table>

    <?= PremiacaoRenderer::renderCategoryTables($categorias, 'screen') ?>

    <div style="margin: 24px 0 36px; text-align: right;">
        <a href="premiacao_pdf.php?id=<?= urlencode($premiacao->getPremiacaoId()) ?>" target="_blank" rel="noopener" style="display: inline-block; padding: 10px 14px; background: #1f4d7a; color: white; text-decoration: none; border-radius: 4px;">Gerar PDF</a>
    </div>
</div>
<?php
Template::printFooter();
