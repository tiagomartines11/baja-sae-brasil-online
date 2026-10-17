<?php
/**
 * Asks the PHP-FPM worker pools how busy they are, from INSIDE the app
 * container, and prints the answer as one line of JSON:
 *
 *   docker compose exec baja-app php scripts/contagem-fpm-status.php
 *
 *   {"www": {"active processes": 2, "idle processes": 3, ...}, "contagem": {...}}
 *
 * Each pool keeps these numbers itself (`pm.status_path`, see
 * baja-infra/baja-app/php-fpm-pools.conf):
 *
 *   active processes       workers busy at this instant
 *   max active processes   the most that were ever busy at once
 *   listen queue           requests waiting for a free worker right now
 *   max listen queue       the longest that queue ever was
 *   max children reached   how often a request found every worker busy
 *   accepted conn          requests served since the pool started
 *
 * The load test samples this every second. It is the honest measure of
 * "do we have enough workers": it counts work done after the answer was
 * sent (the whole-race recalculation), which no client can see, and it
 * does not count the network, which no worker spends time on.
 *
 * The status page is not routed by nginx, so it has to be asked for in
 * FastCGI, the protocol nginx itself uses to talk to PHP-FPM. The few
 * lines of it needed here are written out below; there is no library for
 * it in the image.
 */

const POOLS = ['www' => 9000, 'contagem' => 9001];
const STATUS_PATH = '/fpm-status';

// FastCGI record types.
const FCGI_BEGIN_REQUEST = 1;
const FCGI_PARAMS = 4;
const FCGI_STDIN = 5;
const FCGI_STDOUT = 6;
const FCGI_END_REQUEST = 3;

/** One FastCGI record: an 8-byte header followed by the content. */
function fcgiRecord(int $type, string $content): string
{
    // version 1, type, request id 1, content length, no padding, reserved
    return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0) . $content;
}

/** A name/value pair in FastCGI's encoding (both lengths under 128 here). */
function fcgiPair(string $name, string $value): string
{
    return chr(strlen($name)) . chr(strlen($value)) . $name . $value;
}

/**
 * @return array<string, mixed>|null the pool's status, null when it did not answer
 */
function poolStatus(int $port): ?array
{
    $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorText, 2.0);
    if ($socket === false) {
        return null;
    }
    stream_set_timeout($socket, 2);

    $params = fcgiPair('REQUEST_METHOD', 'GET')
        . fcgiPair('SCRIPT_NAME', STATUS_PATH)
        . fcgiPair('SCRIPT_FILENAME', STATUS_PATH)
        . fcgiPair('REQUEST_URI', STATUS_PATH . '?json')
        . fcgiPair('QUERY_STRING', 'json');

    // role "responder" (1), and no flags: close the connection when done.
    fwrite($socket, fcgiRecord(FCGI_BEGIN_REQUEST, pack('nCx5', 1, 0)));
    fwrite($socket, fcgiRecord(FCGI_PARAMS, $params));
    fwrite($socket, fcgiRecord(FCGI_PARAMS, ''));
    fwrite($socket, fcgiRecord(FCGI_STDIN, ''));

    $output = '';
    while (!feof($socket)) {
        $header = fread($socket, 8);
        if ($header === false || strlen($header) < 8) {
            break;
        }
        $fields = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $header);
        $content = $fields['length'] > 0 ? (string) stream_get_contents($socket, $fields['length']) : '';
        if ($fields['padding'] > 0) {
            stream_get_contents($socket, $fields['padding']);
        }
        if ($fields['type'] === FCGI_STDOUT) {
            $output .= $content;
        } elseif ($fields['type'] === FCGI_END_REQUEST) {
            break;
        }
    }
    fclose($socket);

    // The output is HTTP-like: headers, a blank line, then the JSON body.
    $body = explode("\r\n\r\n", $output, 2)[1] ?? '';
    $status = json_decode($body, true);
    return is_array($status) ? $status : null;
}

$statusByPool = [];
foreach (POOLS as $name => $port) {
    $statusByPool[$name] = poolStatus($port);
}
echo json_encode($statusByPool), "\n";
