<?php
/**
 * API Documentation Endpoint
 *
 * GET /api/ - Returns JSON documentation of all API endpoints
 *
 * ApiDocs.json is generated, not committed (see .gitignore), so it is absent
 * on a fresh checkout until you build it:
 *
 *   docker compose exec --user "$(id -u):$(id -g)" baja-app \
 *     php -r "require 'vendor/autoload.php'; Baja\Api\GenerateDocs::generate();"
 *
 * The --user flag matters: the container runs as root over a bind mount, so
 * without it the generated file lands on the host owned by root and you
 * cannot edit or delete it without sudo.
 *
 * GenerateDocs only reads the api/ tree, so it deliberately loads the
 * autoloader rather than bootstrap.php — the latter pulls in phpBB login,
 * which has no meaning on the CLI.
 */

require_once(__DIR__ . '/../src/bootstrap.php');

use Baja\Api\Cors;

Cors::handle('GET');

header('Content-Type: application/json; charset=utf-8');

$docsFile = __DIR__ . '/../src/Baja/Api/ApiDocs.json';

if (!file_exists($docsFile)) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Documentation not generated',
        'hint' => 'Run: docker compose exec baja-app php -r "require \'vendor/autoload.php\'; Baja\\Api\\GenerateDocs::generate();"'
    ]);
    exit;
}

// Cache for 5 minutes
header('Cache-Control: public, max-age=300');

readfile($docsFile);
