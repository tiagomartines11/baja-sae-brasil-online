<?php

use Baja\Certificado\Documento;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;

T::group('documento');

// --- CPF input normalization -------------------------------------------------

$cpf = synthetic_cpf('529982247');           // 52998224725, valid check digits
T::same('52998224725', $cpf, 'synthetic CPF helper derives the check digits');

T::same($cpf, Documento::normalizeCpf($cpf), 'unpunctuated CPF is unchanged');
T::same($cpf, Documento::normalizeCpf('529.982.247-25'), 'punctuated CPF normalizes');
T::same($cpf, Documento::normalizeCpf(' 529 982 247 25 '), 'spaced CPF normalizes');

$zeroCpf = synthetic_cpf('012345678');       // 01234567890
T::same('01234567890', $zeroCpf, 'a CPF beginning with zero is still eleven digits');
T::same($zeroCpf, Documento::normalizeCpf($zeroCpf), 'leading-zero CPF is unchanged');
T::same($zeroCpf, Documento::normalizeCpf('1234567890'), 'dropped leading zero is restored');
T::same($zeroCpf, Documento::normalizeCpf('012.345.678-90'), 'punctuated leading-zero CPF normalizes');

T::same(null, Documento::normalizeCpf(''), 'empty input is not a CPF');
T::same(null, Documento::normalizeCpf('abc'), 'letters alone are not a CPF');
T::same(null, Documento::normalizeCpf('123456789012'), 'twelve digits are not a CPF');
T::ok(
    'a longer value is rejected rather than truncated',
    Documento::normalizeCpf('123456789012345') === null
);

// --- check digits ------------------------------------------------------------

T::ok('valid CPF passes', Documento::isValidCpf($cpf));
T::ok('valid leading-zero CPF passes', Documento::isValidCpf($zeroCpf));
T::ok('punctuated valid CPF passes', Documento::isValidCpf('529.982.247-25'));
T::ok('typo\'d CPF fails', !Documento::isValidCpf('52998224724'));
T::ok('repeated digits rejected', !Documento::isValidCpf('11111111111'));
T::ok('ten digits fail', !Documento::isValidCpf('5299822472'));

// --- foreign documents -------------------------------------------------------

T::same('AB123456', Documento::normalizeEstrangeiro('ab-123456'), 'foreign document folds case and punctuation');
T::same('AB123456', Documento::normalizeEstrangeiro('00AB123456'), 'leading zeros stripped for comparison');
T::same('123456', Documento::normalizeEstrangeiro('000123456'), 'digits-only foreign document strips zeros');

// --- masking for a phone screen ----------------------------------------------

T::same('••••••••725', Documento::mascarar($cpf), 'a CPF keeps its last three digits');
T::same('••••••••890', Documento::mascarar($zeroCpf), 'and so does one that begins with zeros');
T::same(
    11,
    mb_strlen(Documento::mascarar($cpf), 'UTF-8'),
    'the mask is as long as the number, so a CPF still reads as a CPF'
);
T::same('•••••456', Documento::mascarar('AB123456'), 'a passport is masked the same way');
T::same('12', Documento::mascarar('12'), 'a value with nothing to hide is returned as it is');
T::same('', Documento::mascarar(''), 'and so is an absent document');

if (!test_db_available()) {
    T::skip('database-backed document tests', 'BAJA_TEST_DB is not 1');
    return;
}

$evento = \Baja\Model\EventoQuery::create()->findOne();
$fixtures = [];
$make = static function (string $nome) use ($evento, &$fixtures): Participante {
    $fixtures[] = $nome;
    $p = new Participante();
    $p->setNome($nome);
    $p->setFuncao('competidor');
    $p->setEventoId($evento->getEventoId());
    $p->setCriadoPor(test_user_id());

    return $p;
};

// --- the write path pads, and never pads a foreign document ------------------

$p = $make('Fixture Documento Padding');
$p->setCpf('1234567890');
$p->save();
T::same($zeroCpf, $p->getCpf(), 'saving pads a CPF to eleven digits');

$reloaded = ParticipanteQuery::create()->filterByNome('Fixture Documento Padding')->findOne();
T::same($zeroCpf, $reloaded->getCpf(), 'the padded CPF round-trips through the database');

$f = $make('Fixture Documento Estrangeiro');
$f->setDocumentoEstrangeiro('AB123456');
$f->save();
$reloadedForeign = ParticipanteQuery::create()->filterByNome('Fixture Documento Estrangeiro')->findOne();
T::same('AB123456', $reloadedForeign->getDocumentoEstrangeiro(), 'an alphanumeric passport stores verbatim');

$z = $make('Fixture Documento Zeros');
$z->setDocumentoEstrangeiro('00123456');
$z->save();
$reloadedZeros = ParticipanteQuery::create()->filterByNome('Fixture Documento Zeros')->findOne();
T::same('00123456', $reloadedZeros->getDocumentoEstrangeiro(), 'a foreign document keeps its own leading zeros');

// --- refusals ----------------------------------------------------------------

$both = $make('Fixture Documento Ambos');
$both->setCpf($cpf);
$both->setDocumentoEstrangeiro('AB999999');
$refusedBoth = false;
try {
    $both->save();
} catch (\InvalidArgumentException $e) {
    $refusedBoth = true;
    array_pop($fixtures);
}
T::ok('a row cannot hold both a CPF and a foreign document', $refusedBoth);

$tooLong = $make('Fixture Documento Longo');
$tooLong->setCpf('123456789012');
$refusedLong = false;
try {
    $tooLong->save();
} catch (\InvalidArgumentException $e) {
    $refusedLong = true;
    array_pop($fixtures);
}
T::ok('a twelve-digit value is refused rather than filed as a CPF', $refusedLong);

// --- the CHECK constraint catches what bypasses the model --------------------

$connection = \Propel\Runtime\Propel::getWriteConnection(\Baja\Model\Map\ParticipanteTableMap::DATABASE_NAME);
$rejectedByDb = false;
try {
    $statement = $connection->prepare(
        'INSERT INTO participantes (nome, funcao, cpf, evento, token) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([
        'Fixture Documento Bypass', 'competidor', '1234567890',
        $evento->getEventoId(), \Baja\Certificado\Token::generate(),
    ]);
    $fixtures[] = 'Fixture Documento Bypass';
} catch (\Throwable $e) {
    $rejectedByDb = true;
}
T::ok('the CHECK constraint refuses an unpadded CPF written around the model', $rejectedByDb);

foreach ($fixtures as $name) {
    ParticipanteQuery::create()->filterByNome($name)->delete();
}
