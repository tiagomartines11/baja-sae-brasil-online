<?php

use Baja\Certificado\Token;
use Baja\Model\EventoQuery;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;

T::group('auditoria');

if (!test_db_available()) {
    T::skip('audit column tests', 'BAJA_TEST_DB is not 1');
    return;
}

$evento = EventoQuery::create()->findOne();
if (!$evento) {
    T::skip('audit column tests', 'no events in the database');
    return;
}

$prefix = 'ZZFixtureAuditoria';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()
        ->filterByNome($prefix . '%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)
        ->delete();
};
$cleanup();

$make = static function (string $nome) use ($prefix, $evento): Participante {
    $p = new Participante();
    $p->setNome($prefix . ' ' . $nome);
    $p->setFuncao('competidor');
    $p->setEventoId($evento->getEventoId());

    return $p;
};

// --- nothing is created without an author ------------------------------------

$semAutor = $make('Sem Autor');
$recusado = false;
try {
    $semAutor->save();
} catch (\LogicException $e) {
    $recusado = true;
}
T::ok('an insert with no criado_por is refused', $recusado);
T::same(
    0,
    ParticipanteQuery::create()->filterByNome($prefix . ' Sem Autor')->count(),
    'and no row reaches the table'
);

// --- what a normal insert fills in -------------------------------------------

$row = $make('Completo');
$row->setCriadoPor(test_user_id());
$row->save();

T::ok('token is populated', Token::isWellFormed((string) $row->getToken()));
T::same(test_user_id(), (int) $row->getCriadoPor(), 'criado_por is what the caller set');
T::ok('criado_em is populated', $row->getCriadoEm() instanceof \DateTimeInterface);
T::ok('lote_id is populated', Token::isWellFormed((string) $row->getLoteId()));
T::notSame($row->getToken(), $row->getLoteId(), 'the batch id is not the row token');

// Read it back rather than trusting the in-memory object: the columns have to
// survive the round trip, which is what the charset and type choices decide.
$reloaded = ParticipanteQuery::create()->filterByToken($row->getToken())->findOne();
T::same(test_user_id(), (int) $reloaded->getCriadoPor(), 'criado_por survives the round trip');
T::same((string) $row->getLoteId(), (string) $reloaded->getLoteId(), 'lote_id survives the round trip');
T::ok('criado_em survives the round trip', $reloaded->getCriadoEm() instanceof \DateTimeInterface);

// --- a batch id given by the caller is kept -----------------------------------
//
// The paste flow generates one id for the whole batch, so preInsert must not
// replace it per row — that would make every row its own batch and nothing
// identifiable afterwards.

$lote = Token::generate();
$umaLinha  = $make('Lote A');
$umaLinha->setCriadoPor(test_user_id());
$umaLinha->setLoteId($lote);
$umaLinha->save();

$outraLinha = $make('Lote B');
$outraLinha->setCriadoPor(test_user_id());
$outraLinha->setLoteId($lote);
$outraLinha->save();

T::same($lote, (string) $umaLinha->getLoteId(), 'an explicit lote_id is not overwritten');
T::same(
    2,
    ParticipanteQuery::create()->filterByLoteId($lote)->count(),
    'both rows of the batch share one lote_id'
);

// --- lote_id is case-sensitive -----------------------------------------------
//
// Same reasoning as the token: base64url is case-sensitive and the table
// default collation is not. Under latin1_swedish_ci a deletion by lote_id
// would take rows belonging to a different batch.

$mixed = 'aB' . substr(Token::generate(), 2);
$swapped = strtoupper(substr($mixed, 0, 2)) === 'AB'
    ? 'Ab' . substr($mixed, 2)
    : 'aB' . substr($mixed, 2);

$rowMixed = $make('Caixa Mista');
$rowMixed->setCriadoPor(test_user_id());
$rowMixed->setLoteId($mixed);
$rowMixed->save();

T::same(
    1,
    ParticipanteQuery::create()->filterByLoteId($mixed)->count(),
    'the batch id matches its own case'
);
T::same(
    0,
    ParticipanteQuery::create()->filterByLoteId($swapped)->count(),
    'and a case-differing batch id matches nothing'
);

// --- historical rows keep NULL ------------------------------------------------
//
// The constraint is on creating rows, not on holding them. Rows that predate
// this branch have nothing to backfill from and must stay readable.

$semAuditoria = $make('Historico');
$semAuditoria->setCriadoPor(test_user_id());
$semAuditoria->save();

$con = \Propel\Runtime\Propel::getConnection();
$stmt = $con->prepare(
    'UPDATE participantes SET criado_por = NULL, criado_em = NULL, lote_id = NULL WHERE token = ?'
);
$stmt->execute([$semAuditoria->getToken()]);

\Baja\Model\Map\ParticipanteTableMap::clearInstancePool();
$historico = ParticipanteQuery::create()->filterByToken($semAuditoria->getToken())->findOne();
T::same(null, $historico->getCriadoPor(), 'an existing row may hold no author');
T::same(null, $historico->getCriadoEm(), 'an existing row may hold no timestamp');
T::same(null, $historico->getLoteId(), 'an existing row may hold no batch');

// An update to such a row must not trip the insert-time rule.
$historico->setNome($prefix . ' Historico Renomeado');
$atualizou = true;
try {
    $historico->save();
} catch (\Throwable $e) {
    $atualizou = false;
}
T::ok('a historical row can still be updated', $atualizou);

$cleanup();
