<?php

use Baja\Certificado\Busca;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;

T::group('busca');

if (!test_db_available()) {
    T::skip('lookup tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventos = \Baja\Model\EventoQuery::create()->limit(3)->find();
if (count($eventos) < 3) {
    T::skip('lookup tests', 'need at least three events');
    return;
}
$eventoIds = [];
foreach ($eventos as $evento) {
    $eventoIds[] = $evento->getEventoId();
}

$prefix   = 'ZZFixtureBusca';
$fixtures = [];

$make = static function (string $nome, ?string $cpf, ?string $estrangeiro, string $eventoId) use ($prefix, &$fixtures): void {
    $p = new Participante();
    $p->setNome($prefix . ' ' . $nome);
    $p->setFuncao('competidor');
    $p->setCpf($cpf);
    $p->setDocumentoEstrangeiro($estrangeiro);
    $p->setEventoId($eventoId);
    $p->save();
    $fixtures[] = $p->getToken();
};

$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()->filterByNome($prefix . '%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->delete();
};

$cleanup();

// A participant across three events, the middle one with the name mistyped.
$cpfTresEventos = synthetic_cpf('112233445');
$make('Joana Pereira Antunes', $cpfTresEventos, null, $eventoIds[0]);
$make('Joana P. Antunes',      $cpfTresEventos, null, $eventoIds[1]);
$make('Joana Pereyra Antunes', $cpfTresEventos, null, $eventoIds[2]);

// A leading-zero CPF.
$cpfZero = synthetic_cpf('001234567');
$make('Carlos Eduardo Prado', $cpfZero, null, $eventoIds[0]);

// A foreign participant, digits-only with a leading zero, as the legacy habit
// recorded them.
$make('Anna Kowalski Nowak', null, '00987654', $eventoIds[0]);

// A foreign participant whose passport happens to satisfy the CPF check digits.
$passaporteValidoComoCpf = synthetic_cpf('998877665');
$make('Lars Erik Johansson', null, $passaporteValidoComoCpf, $eventoIds[0]);

// A Brazilian whose CPF was mistyped at registration and fails the check digits.
$cpfComTypo = substr(synthetic_cpf('223344556'), 0, 10) . '9';
if (\Baja\Certificado\Documento::isValidCpf($cpfComTypo)) {
    $cpfComTypo = substr($cpfComTypo, 0, 10) . '8';
}
$make('Rita Ferreira Lopes', $cpfComTypo, null, $eventoIds[0]);

$names = static function (array $certificados): array {
    $out = [];
    foreach ($certificados as $certificado) {
        $out[] = $certificado->getNome();
    }
    sort($out);

    return $out;
};

// --- cross-row rule ----------------------------------------------------------

$found = Busca::run($cpfTresEventos, 'Joana Pereira Antunes');
T::same(3, count($found), 'a name matching one row returns all three of that document\'s rows');
T::same(
    [$prefix . ' Joana P. Antunes', $prefix . ' Joana Pereira Antunes', $prefix . ' Joana Pereyra Antunes'],
    $names($found),
    'each row is shown under the name stored on it'
);

$found = Busca::run($cpfTresEventos, 'Joana Pereyra Antunes');
T::same(3, count($found), 'matching the mistyped row also returns all three');

// Tokens pooled across two rows must not add up to a match: "Pereira" is on
// row 1 and "Pereyra" on row 3, and no single row carries both.
$found = Busca::run($cpfTresEventos, 'Pereira Pereyra');
T::same(0, count($found), 'tokens pooled across rows do not produce a match');

$found = Busca::run($cpfTresEventos, 'Joana');
T::same(0, count($found), 'a bare first name returns nothing');

$found = Busca::run($cpfTresEventos, 'Marcos Pereira Antunes');
T::same(0, count($found), 'a different first name returns nothing');

// --- document normalization --------------------------------------------------

$punctuated  = substr($cpfZero, 0, 3) . '.' . substr($cpfZero, 3, 3) . '.' . substr($cpfZero, 6, 3) . '-' . substr($cpfZero, 9, 2);
$zeroDropped = ltrim($cpfZero, '0');

foreach ([
    'zero-padded'  => $cpfZero,
    'punctuated'   => $punctuated,
    'zero dropped' => $zeroDropped,
] as $label => $form) {
    $found = Busca::run($form, 'Carlos Prado');
    T::same(1, count($found), "a leading-zero CPF resolves when submitted $label");
}

// --- documents that are not well-formed CPFs still resolve -------------------

$found = Busca::run($cpfComTypo, 'Rita Lopes');
T::same(1, count($found), 'a CPF that fails the check digits still resolves');

$found = Busca::run('00987654', 'Anna Nowak');
T::same(1, count($found), 'a foreign document resolves with no document-type choice');

$found = Busca::run('987654', 'Anna Nowak');
T::same(1, count($found), 'a foreign document resolves with its leading zeros dropped');

$found = Busca::run($passaporteValidoComoCpf, 'Lars Johansson');
T::same(1, count($found), 'a passport that passes the CPF check digits still resolves');

// --- passports, whichever side is missing the letters ------------------------

/*
 * Randomized per run. A document number is not scoped by the fixture name
 * prefix, and the cross-row rule returns every row filed under it — so a
 * hardcoded number that happens to exist in the developer's database makes
 * these counts fail for a reason that has nothing to do with the code.
 */
$passportDigits = (string) random_int(200000, 899999);
$legacyDigits   = (string) random_int(200000, 899999);

$make('Ingrid Larsen Berg', null, 'AB' . $passportDigits, $eventoIds[0]);

foreach ([
    'as recorded'        => 'AB' . $passportDigits,
    'digits only'        => $passportDigits,
    'punctuated'         => 'ab-' . $passportDigits,
    'with leading zeros' => '00' . $passportDigits,
] as $label => $form) {
    $found = Busca::run($form, 'Ingrid Berg');
    T::same(1, count($found), "a passport recorded with letters resolves when submitted $label");
}

// And the legacy direction: recorded digits-only, because the old column could
// not hold letters, but the person types the passport they actually hold.
$make('Petra Novak Horak', null, $legacyDigits, $eventoIds[0]);
foreach ([
    'digits only'  => $legacyDigits,
    'with letters' => 'CD' . $legacyDigits,
] as $label => $form) {
    $found = Busca::run($form, 'Petra Horak');
    T::same(1, count($found), "a passport recorded digits-only resolves when submitted $label");
}

// The suffix prefilter must not become the rule.
$make('Sven Olsen Dahl', null, 'AB999' . $passportDigits, $eventoIds[0]);
$found = Busca::run($passportDigits, 'Sven Dahl');
T::same(0, count($found), 'a longer number merely ending in the submitted digits does not resolve');
$found = Busca::run($passportDigits, 'Ingrid Berg');
T::same(1, count($found), 'and the genuine holder of those digits still does');

// --- no oracle ---------------------------------------------------------------

$unknownDocument = Busca::run(synthetic_cpf('987654321'), 'Joana Pereira Antunes');
$knownWrongName  = Busca::run($cpfTresEventos, 'Fulana Improvavel Inexistente');
T::same([], $unknownDocument, 'an unknown document returns nothing');
T::same([], $knownWrongName, 'a known document with the wrong name returns nothing');
T::same($unknownDocument, $knownWrongName, 'both failures return the same value');

// The decoy comparison is what makes the two paths cost the same. Timing on a
// shared runner is noisy, so this asserts only that neither path is orders of
// magnitude cheaper than the other.
$time = static function (callable $fn): float {
    $start = microtime(true);
    for ($i = 0; $i < 40; $i++) {
        $fn();
    }

    return microtime(true) - $start;
};
$tUnknown = $time(static fn () => Busca::run(synthetic_cpf('987654321'), 'Joana Pereira Antunes'));
$tWrong   = $time(static fn () => Busca::run($cpfTresEventos, 'Fulana Improvavel Inexistente'));
$ratio    = max($tUnknown, $tWrong) / max(0.000001, min($tUnknown, $tWrong));
T::ok(
    'the two failure paths take comparable time',
    $ratio < 10,
    sprintf('ratio %.1f (unknown %.4fs, wrong name %.4fs)', $ratio, $tUnknown, $tWrong)
);

// --- the document rule, reusable ----------------------------------------------
//
// rowsForDocument() and rowMatches() are what the insertion pages ask before
// they decide whether a row they are about to create already exists. The two
// have to agree with each other, because the paste flow uses the second
// against rows it fetched in bulk while the single-entry page uses the first.

$linhasDoCpf = Busca::rowsForDocument($cpfTresEventos);
T::same(3, count($linhasDoCpf), 'rowsForDocument finds every event for one CPF');

foreach ($linhasDoCpf as $linha) {
    T::ok('rowMatches agrees with rowsForDocument', Busca::rowMatches($linha, $cpfTresEventos));
}

$outra = ParticipanteQuery::create()->filterByNome($prefix . ' Carlos Eduardo Prado')->findOne();
T::ok('rowMatches rejects a row filed under another document', !Busca::rowMatches($outra, $cpfTresEventos));

// The foreign side, where the two could most easily disagree: the query uses a
// suffix match and the rule narrows it back to equal digits.
$estrangeira = ParticipanteQuery::create()->filterByNome($prefix . ' Anna Kowalski Nowak')->findOne();
T::ok('rowMatches reaches a digits-only passport from a lettered one', Busca::rowMatches($estrangeira, 'AB987654'));
T::ok('rowMatches ignores leading zeros', Busca::rowMatches($estrangeira, '000987654'));
T::ok('rowMatches is not a mere suffix', !Busca::rowMatches($estrangeira, '7654'));

$cleanup();
