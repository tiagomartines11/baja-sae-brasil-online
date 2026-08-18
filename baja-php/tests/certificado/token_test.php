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

// --- the key is case-sensitive -----------------------------------------------
//
// Two tokens differing only in case are two tokens. Under the table's default
// latin1_swedish_ci they would be the same key, the second insert would be
// rejected as a duplicate, and a lookup would return whichever row got there
// first — someone else's certificate. ascii_bin on the column is what prevents
// that, and this is the test that would catch its loss.
//
// Written as two inserts rather than by rotating one row's token, because
// since 2c the token is the primary key: changing it in place is a delete and
// an insert, not an update. Tokens are never rotated, so this costs nothing
// in practice, but it is worth knowing.

$suffix = substr(Token::generate(), 2);
$lower  = 'aB' . $suffix;
$upper  = 'Ab' . $suffix;

$rowLower = new Participante();
$rowLower->setNome('Fixture Token Minusculo');
$rowLower->setFuncao('competidor');
$rowLower->setEventoId($evento->getEventoId());
$rowLower->setToken($lower);
$rowLower->save();
$fixtureNames[] = 'Fixture Token Minusculo';

$rowUpper = new Participante();
$rowUpper->setNome('Fixture Token Maiusculo');
$rowUpper->setFuncao('competidor');
$rowUpper->setEventoId($evento->getEventoId());
$rowUpper->setToken($upper);
$rowUpper->save();
$fixtureNames[] = 'Fixture Token Maiusculo';

$hitLower = ParticipanteQuery::create()->filterByToken($lower)->findOne();
$hitUpper = ParticipanteQuery::create()->filterByToken($upper)->findOne();

T::ok('a case-differing token is accepted as a distinct key', $hitLower !== null && $hitUpper !== null);
T::same('Fixture Token Minusculo', $hitLower ? $hitLower->getNome() : null, 'the lowercase token resolves to its own row');
T::same('Fixture Token Maiusculo', $hitUpper ? $hitUpper->getNome() : null, 'the case-swapped token resolves to the other row');

// --- the key rejects an exact duplicate --------------------------------------

$duplicate = new Participante();
$duplicate->setNome('Fixture Token Duplicado');
$duplicate->setFuncao('competidor');
$duplicate->setEventoId($evento->getEventoId());
$duplicate->setToken($lower);
$rejected = false;
try {
    $duplicate->save();
    $fixtureNames[] = 'Fixture Token Duplicado';
} catch (\Throwable $e) {
    $rejected = true;
}
T::ok('the primary key rejects a duplicate token', $rejected);

foreach ($fixtureNames as $name) {
    ParticipanteQuery::create()->filterByNome($name)->delete();
}
