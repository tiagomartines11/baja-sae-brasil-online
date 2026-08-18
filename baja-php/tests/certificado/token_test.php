<?php

use Baja\Certificado\Token;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;

T::group('token');

$token = Token::generate();
T::same(22, strlen($token), 'generated token is 22 characters');
T::ok('generated token is base64url only', preg_match('/\A[A-Za-z0-9_-]+\z/', $token) === 1, $token);
T::notSame(Token::generate(), Token::generate(), 'two generated tokens differ');

$many = [];
for ($i = 0; $i < 20000; $i++) {
    $many[Token::generate()] = true;
}
T::same(20000, count($many), '20k generated tokens are all distinct');

T::ok('well-formed token accepted', Token::isWellFormed($token));
T::ok('21 characters rejected', !Token::isWellFormed(substr($token, 0, 21)));
T::ok('23 characters rejected', !Token::isWellFormed($token . 'x'));
T::ok('base64 padding rejected', !Token::isWellFormed('AAAAAAAAAAAAAAAAAAAA=='));
T::ok('non-base64url character rejected', !Token::isWellFormed('AAAAAAAAAAAAAAAAAAAA+/'));
T::ok('empty string rejected', !Token::isWellFormed(''));
T::ok('SQL-ish junk rejected', !Token::isWellFormed("' OR 1=1 --"));

if (!test_db_available()) {
    T::skip('database-backed token tests', 'BAJA_TEST_DB is not 1');
    return;
}

// --- token is assigned automatically on insert -------------------------------

$evento = \Baja\Model\EventoQuery::create()->findOne();
if (!$evento) {
    T::skip('insert hook', 'no evento rows to attach a participant to');
    return;
}

$fixtureNames = [];
$makeRow = static function (string $nome) use ($evento, &$fixtureNames): Participante {
    $fixtureNames[] = $nome;
    $p = new Participante();
    $p->setNome($nome);
    $p->setFuncao('competidor');
    $p->setCpf(synthetic_cpf('012345678'));
    $p->setEventoId($evento->getEventoId());
    $p->save();

    return $p;
};

$inserted = $makeRow('Fixture Token Insercao');
T::ok('insert assigns a token without the caller asking', Token::isWellFormed((string) $inserted->getToken()));

$explicit = new Participante();
$explicit->setNome('Fixture Token Explicito');
$explicit->setFuncao('competidor');
$explicit->setCpf(synthetic_cpf('012345678'));
$explicit->setEventoId($evento->getEventoId());
$chosen = Token::generate();
$explicit->setToken($chosen);
$explicit->save();
$fixtureNames[] = 'Fixture Token Explicito';
T::same($chosen, $explicit->getToken(), 'an explicitly set token is not overwritten');

// --- lookups are case-sensitive ----------------------------------------------

$mixed = 'aB' . substr($inserted->getToken(), 2);
$swapped = 'Ab' . substr($inserted->getToken(), 2);
$inserted->setToken($mixed);
$inserted->save();

$hit  = ParticipanteQuery::create()->filterByToken($mixed)->findOne();
$miss = ParticipanteQuery::create()->filterByToken($swapped)->findOne();
T::ok('token lookup finds the exact case', $hit !== null);
T::ok('token lookup does not match a case-swapped token', $miss === null);

// --- the unique index actually rejects a duplicate ---------------------------

$duplicate = new Participante();
$duplicate->setNome('Fixture Token Duplicado');
$duplicate->setFuncao('competidor');
$duplicate->setCpf(synthetic_cpf('012345678'));
$duplicate->setEventoId($evento->getEventoId());
$duplicate->setToken($mixed);
$rejected = false;
try {
    $duplicate->save();
    $fixtureNames[] = 'Fixture Token Duplicado';
} catch (\Throwable $e) {
    $rejected = true;
}
T::ok('unique index rejects a duplicate token', $rejected);

foreach ($fixtureNames as $name) {
    ParticipanteQuery::create()->filterByNome($name)->delete();
}
