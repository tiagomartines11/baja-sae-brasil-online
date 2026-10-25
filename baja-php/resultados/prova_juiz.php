<?php
use Baja\Site\CachePolicy;
use Baja\Site\CleanUrl;

// The judges' former copy of the results page. prova.php now serves judges
// the live page on its own URL (docs/results-caching.md), so this only sends
// old links and bookmarks there, keeping the id and the event prefix
// (/26BR/prova_juiz.php?id=X -> /26BR/prova.php?id=X).
//
// nginx gives this file the forum session, so a judge following an old link
// also gets the staff cookie here (src/bootstrap.php) and lands on the live
// page. A response produced with a session is never cached.
CachePolicy::sendPrivate();

$path = CleanUrl::safePath((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = preg_replace('#prova_juiz\.php$#', 'prova.php', $path);
$id = $_GET['id'] ?? null;
header('Location: ' . $path . (is_string($id) ? '?id=' . rawurlencode($id) : ''), true, 301);
exit;
