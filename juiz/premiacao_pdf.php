<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\PremiacaoQuery;
use Baja\Premiacao\Renderer as PremiacaoRenderer;
use Baja\Premiacao\Resolver as PremiacaoResolver;
use Baja\Session;
use Dompdf\Dompdf;

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
$html = PremiacaoRenderer::renderPdfDocument($currentEvent->getNome(), $premiacao, $categorias);

$dompdf = new Dompdf();
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->get_canvas();
$fontMetrics = $dompdf->getFontMetrics();
$font = $fontMetrics->getFont('Helvetica', 'normal');
$pageTextWidth = $fontMetrics->getTextWidth('00/00', $font, 9);
$pageWidth = method_exists($canvas, 'get_width') ? $canvas->get_width() : 595;
$pageHeight = method_exists($canvas, 'get_height') ? $canvas->get_height() : 842;
$canvas->page_text($pageWidth - 22 - $pageTextWidth, $pageHeight - 18, '{PAGE_NUM}/{PAGE_COUNT}', $font, 9, array(0.45, 0.45, 0.45));

$filename = 'premiacao_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $premiacao->getPremiacaoId()) . '.pdf';
$dompdf->stream($filename, array('Attachment' => 0));
