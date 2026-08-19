<?php

use Baja\Certificado\Certificado;
use Baja\Certificado\Pdf;
use Baja\Certificado\QrCode;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;

T::group('pdf');

// --- the QR code itself ------------------------------------------------------

$url = 'http://certificado.baja.local/verificar/AAAAAAAAAAAAAAAAAAAAAA';
$uri = QrCode::dataUri($url);

T::ok('QR renders as a PNG data URI', str_starts_with($uri, 'data:image/png;base64,'));

$png   = base64_decode(substr($uri, strlen('data:image/png;base64,')));
$image = imagecreatefromstring($png);
T::ok('QR PNG decodes', $image !== false);

$size = imagesx($image);
T::same($size, imagesy($image), 'QR is square');

// Ten pixels per module, four modules of quiet zone each side. Printed at
// 90pt the symbol lands near 360dpi, well past the 3x oversampling that keeps
// module edges hard on paper.
T::ok('QR is oversampled for print', $size >= 300, sprintf('%dpx', $size));
T::ok(
    'QR keeps its quiet zone',
    (imagecolorat($image, 2, 2) & 0xFFFFFF) === 0xFFFFFF
        && (imagecolorat($image, $size - 3, 2) & 0xFFFFFF) === 0xFFFFFF,
    'a code cropped flush to the border fails on some readers'
);

if (!test_db_available()) {
    T::skip('certificate rendering per role', 'BAJA_TEST_DB is not 1');
    return;
}

$evento = \Baja\Model\EventoQuery::create()->findOne();

/*
 * Every role, not just the one that happened to be handy. The body copy varies
 * by funcao — a comite certificate reads "Realizou trabalho voluntário na
 * organização da…", which is not what a competitor's says — and a template
 * edit that renders for one branch can fail on another.
 */
$roles = [
    'competidor' => 'Participou da',
    'comissario' => 'COMISSÁRIO',
    'juiz'       => 'JUIZ',
    'comite'     => 'COMISSÃO TÉCNICA',
    'engenheiro' => 'ENGENHEIRO',
    'orientador' => 'PROFESSOR ORIENTADOR',
    'assessor'   => 'ASSESSOR TÉCNICO',
];

$fixtureName = 'ZZFixturePdf Papel Teste';
ParticipanteQuery::create()->filterByNome($fixtureName)->delete();

foreach ($roles as $role => $expected) {
    $participante = new Participante();
    $participante->setNome($fixtureName);
    $participante->setFuncao($role);
    $participante->setEventoId($evento->getEventoId());
    $participante->setCriadoPor(test_user_id());
    $participante->save();

    $certificado = Certificado::fromParticipante($participante);
    T::ok("role $role produces its own body copy", str_contains($certificado->getTexto(), $expected));
    T::ok("role $role has a label", $certificado->getFuncaoLabel() !== '');

    $pdf = Pdf::render($certificado);
    T::ok("role $role renders a PDF", str_starts_with($pdf, '%PDF'));
    T::ok("role $role PDF is not empty", strlen($pdf) > 50000, strlen($pdf) . ' bytes');

    $participante->delete();
}

// An unrecognised role must not fabricate a claim about what someone did.
$participante = new Participante();
$participante->setNome($fixtureName);
$participante->setFuncao('papel-desconhecido');
$participante->setEventoId($evento->getEventoId());
$participante->setCriadoPor(test_user_id());
$participante->save();
$certificado = Certificado::fromParticipante($participante);
T::same('', $certificado->getTexto(), 'an unknown role produces no body copy');
T::same('', $certificado->getFuncaoLabel(), 'an unknown role produces no label');
$participante->delete();

ParticipanteQuery::create()->filterByNome($fixtureName)->delete();
