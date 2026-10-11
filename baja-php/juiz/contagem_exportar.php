<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\CsvExport;
use Baja\Contagem\Review\Pagina;
use InvalidArgumentException;

// CSV download: GET ?p=&tipo=passagens|decisoes|voltas|paradas. Read-only.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$type = is_string($_GET['tipo'] ?? null) ? $_GET['tipo'] : '';

try {
    CsvExport::stream($prova, $type);
} catch (InvalidArgumentException $invalid) {
    http_response_code(400);
    die(Pagina::h($invalid->getMessage()));
}
