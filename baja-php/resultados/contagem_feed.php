<?php

use Baja\Contagem\Service\PublicFeed;

// Public, read-only picture of a lap-counted race:
//   contagem_feed.php?evt={evento}&p={prova}
//
// For overlays and anyone else who wants live standings. Open to every
// origin, like overlaydata.php, because overlays are pages on other hosts.
//
// This script never recalculates anything: PublicFeed assembles the answer
// from the snapshot the last recalculation left behind. nginx serves it
// with SKIP_AUTH (no forum session is involved), and the cache headers let
// Cloudflare absorb the audience.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json; charset=utf-8');

$eventoId = (string) ($_GET['evt'] ?? '');
$provaId = (string) ($_GET['p'] ?? '');

// The ids end up in a cache key; anything that is not shaped like an id is
// refused before it gets there.
$json = null;
if (preg_match('/^[0-9A-Za-z]{4}$/', $eventoId) === 1 && preg_match('/^[0-9A-Za-z]{1,3}$/', $provaId) === 1) {
    $json = PublicFeed::jsonFor($eventoId, $provaId);
}

if ($json === null) {
    http_response_code(404);
    echo json_encode(['erro' => 'prova_nao_encontrada']);
    exit;
}

// s-maxage is for shared caches (Cloudflare): every viewer in the same two
// seconds gets one stored answer, and this server sees one request.
// max-age keeps a single browser from asking more often than that either.
header('Cache-Control: public, max-age=' . PublicFeed::CACHE_SECONDS . ', s-maxage=' . PublicFeed::CACHE_SECONDS);
echo $json;
