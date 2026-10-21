<?php
namespace Baja\Juiz;

use Baja\Contagem\Shell\OfflineShell;

// Serves the service worker of the count and pit pages.
//
// It is a PHP file under juiz/ (and not a plain file under /js/) for two
// reasons: a service worker can only control pages at or below its own URL,
// and /js/ is served with a 30-day cache, which a worker script must not have.
//
// No session and no permission check on purpose: browsers re-fetch this
// script in the background, also after the login session expired, and a
// redirect to the login page there would break the offline shell. The script
// is the same for everybody and contains nothing but file names.

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache');

$shell = [
    'versao' => OfflineShell::assetVersion(),
    'arquivos' => OfflineShell::versionedAssetFiles(),
];

echo 'const CONTAGEM_SHELL = ' . json_encode($shell, JSON_UNESCAPED_SLASHES) . ";\n";
readfile(OfflineShell::serviceWorkerSourcePath());
