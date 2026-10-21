<?php
namespace Baja\Juiz;

use Baja\Model\Timer;
use Baja\Session;

// Turns a sentence into a sound file for the timers, using the stack's own
// text-to-speech service (baja-infra/tts). Called from timer_admin.php.

Session::permissionCheck('TIMER_ADMIN');

header('Content-Type: application/json');

$falha = function ($msg, $code = 400) {
    http_response_code($code);
    die(json_encode(['ok' => false, 'erro' => $msg], JSON_UNESCAPED_UNICODE));
};

$ttsUrl = getenv('TTS_URL');
if (!$ttsUrl) $falha('Texto para fala não está configurado neste servidor.', 503);

$texto = trim((string)@$_POST['texto']);
if ($texto === '' || mb_strlen($texto) > 300) $falha('Informe um texto de até 300 caracteres.');

// The file name comes from the text itself unless one was given.
$nome = trim((string)@$_POST['nome']) ?: $texto;
$slug = trim(preg_replace('/[^a-z0-9]+/', '-', (string)transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $nome)), '-');
$slug = substr($slug, 0, 40);
if ($slug === '') $falha('Nome inválido.');

$arquivo = Timer::GENERATED_DIR . '/' . $slug . '.wav';
$destino = Timer::soundsDir() . '/' . $arquivo;
if (file_exists($destino) && empty($_POST['substituir'])) $falha('Já existe um som com esse nome.', 409);

$wav = @file_get_contents(rtrim($ttsUrl, '/') . '/synthesize', false, stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/json',
    // length_scale stretches the speech: above 1 is slower than the voice's natural pace.
    'content' => json_encode(['text' => $texto, 'length_scale' => max(0.8, min(2.0, (float)(@$_POST['ritmo'] ?: 1.0)))]),
    'timeout' => 30,
]]));
if ($wav === false || substr($wav, 0, 4) !== 'RIFF') $falha('O serviço de texto para fala não respondeu.', 502);

if (@file_put_contents($destino, $wav) === false) $falha('Não foi possível gravar o arquivo.', 500);

echo json_encode(['ok' => true, 'arquivo' => $arquivo]);
