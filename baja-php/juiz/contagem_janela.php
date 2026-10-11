<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\ContagemService;

// Lane timeline for the review screen. Read-only.
//   GET ?p=&de=&ate=   every entry and event between two moments (ms since the epoch)
//   GET ?p=&carro=     one car's whole race

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$service = ContagemService::forProva($prova);
$positiveInt = ['options' => ['min_range' => 1]];

if (isset($_GET['carro'])) {
    $car = filter_var($_GET['carro'], FILTER_VALIDATE_INT, $positiveInt);
    if ($car === false) {
        Pagina::sendJson(400, ['erro' => 'pedido_invalido', 'mensagem' => 'Número de carro inválido.']);
    }
    Pagina::sendJson(200, TimelineWindow::forCar($service, $car));
}

$fromMs = filter_var($_GET['de'] ?? null, FILTER_VALIDATE_INT, $positiveInt);
$untilMs = filter_var($_GET['ate'] ?? null, FILTER_VALIDATE_INT, $positiveInt);
if ($fromMs === false || $untilMs === false || $untilMs <= $fromMs) {
    Pagina::sendJson(400, ['erro' => 'pedido_invalido', 'mensagem' => 'Intervalo inválido.']);
}

Pagina::sendJson(200, TimelineWindow::forWindow($service, $fromMs, $untilMs));
