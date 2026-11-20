<?php

use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Linha;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Validador;
use Baja\Certificado\Token;
use Baja\Model\EventoQuery;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('gravador');

if (!test_db_available()) {
    T::skip('commit tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventos = EventoQuery::create()->orderByEventoId()->limit(2)->find();
if (count($eventos) < 2) {
    T::skip('commit tests', 'need at least two events');
    return;
}
$evA = $eventos[0]->getEventoId();
$evB = $eventos[1]->getEventoId();

$prefix  = 'ZZFixtureGravador';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();
};
$cleanup();

$validador = new Validador();
$gravador  = new Gravador(test_user_id());

$contar = static function () use ($prefix): int {
    return ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->count();
};

// --- a clean batch --------------------------------------------------------------

$brutas = [];
foreach ([['Ana Carolina Testeson', '111222333'], ['Bruno Henrique Testeson', '222333444'], ['Carla Regina Testeson', '333444555']] as $i => [$nome, $base]) {
    $brutas[] = [
        'evento'    => $evA,
        'nome'      => $prefix . ' ' . $nome,
        'funcao'    => 'competidor',
        'documento' => synthetic_cpf($base),
    ];
}

$linhas = $validador->validar($brutas);
foreach ($linhas as $linha) {
    T::same(Linha::OK, $linha->situacao(), 'fixture row ' . $linha->numero . ' validates cleanly');
}

$resultado = $gravador->gravar($linhas);

T::same(3, $resultado->criadas, 'three rows created');
T::same(0, $resultado->atualizadas, 'nothing updated');
T::same(0, $resultado->ignoradas, 'nothing skipped');
T::same(3, $contar(), 'and three rows are in the table');
T::same([$evA], $resultado->eventos, 'the summary names the event');

$criadas = ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->find();
$lotes = [];
foreach ($criadas as $row) {
    T::ok('every created row has a token', Token::isWellFormed((string) $row->getToken()));
    T::same(test_user_id(), (int) $row->getCriadoPor(), 'every created row records its author');
    T::ok('every created row has a timestamp', $row->getCriadoEm() instanceof \DateTimeInterface);
    T::ok('every created row has a batch', Token::isWellFormed((string) $row->getLoteId()));
    $lotes[(string) $row->getLoteId()] = true;
}
T::same(1, count($lotes), 'one lote_id for the whole batch');
T::same($resultado->loteId, array_key_first($lotes), 'and it is the one reported');

$tokens = [];
foreach ($criadas as $row) {
    $tokens[(string) $row->getToken()] = true;
}
T::same(3, count($tokens), 'one token per row, all distinct');

// --- the batch is findable, and deletable ------------------------------------------

T::same(3, count(Gravador::linhasDoLote($resultado->loteId)), 'the batch can be listed');
T::same(0, count(Gravador::linhasDoLote(Token::generate())), 'an unknown batch lists nothing');
T::same(0, count(Gravador::linhasDoLote('nao-e-um-lote')), 'a malformed batch id lists nothing');

T::same(0, Gravador::apagarLote('nao-e-um-lote'), 'a malformed batch id deletes nothing');
T::same(3, Gravador::apagarLote($resultado->loteId), 'a batch deletes by its id');
T::same(0, $contar(), 'and the rows are gone');

// --- atomicity ---------------------------------------------------------------------
//
// An error injected mid-batch must leave zero rows. The failing row is built
// by hand: validation would have caught an over-long name, which is the point —
// this is the case where something gets past validation and MySQL refuses it.

$cleanup();

$bons = $validador->validar([
    ['evento' => $evA, 'nome' => $prefix . ' Davi Souza Testeson',   'funcao' => 'competidor', 'documento' => synthetic_cpf('444555666')],
    ['evento' => $evA, 'nome' => $prefix . ' Elisa Moura Testeson',  'funcao' => 'competidor', 'documento' => synthetic_cpf('555666777')],
]);

$ruim = new Linha(3, $evA, 'irrelevante', 'competidor', synthetic_cpf('666777888'));
$ruim->eventoId = $evA;
$ruim->funcao   = 'competidor';
$ruim->cpf      = synthetic_cpf('666777888');
$ruim->nome     = $prefix . ' ' . str_repeat('N', 400);

T::ok('the hand-built row claims to be writable', $ruim->podeGravar());

$explodiu = false;
try {
    $gravador->gravar(array_merge($bons, [$ruim]));
} catch (\Throwable $e) {
    $explodiu = true;
}
T::ok('a row MySQL refuses aborts the commit', $explodiu);
T::same(0, $contar(), 'and the batch leaves zero rows behind');

// A batch that is not fully resolved is refused before anything is written.
// `engenheiro` is the role used here because it is the one still marked
// deprecated in Funcao::ROLES, so the row carries an unanswered
// FUNCAO_OBSOLETA warning. (`fiscal` was the example until it became an
// active role again; an active role raises nothing and the batch would just
// be written.)
$cleanup();
$naoResolvida = $validador->validar([
    ['evento' => $evA, 'nome' => $prefix . ' Fabio Lima Testeson', 'funcao' => 'engenheiro', 'documento' => synthetic_cpf('777888999')],
]);
$recusou = false;
try {
    $gravador->gravar($naoResolvida);
} catch (\LogicException $e) {
    $recusou = true;
}
T::ok('an unresolved warning refuses the whole batch', $recusou);
T::same(0, $contar(), 'and writes nothing');

// --- duplicates: skip and update ------------------------------------------------------

$cleanup();
$cpfDup = synthetic_cpf('888999000');
$primeira = $validador->validar([
    ['evento' => $evA, 'nome' => $prefix . ' Gustavo Pinto Testeson', 'funcao' => 'juiz', 'documento' => $cpfDup],
]);
$loteOriginal = $gravador->gravar($primeira)->loteId;
T::same(1, $contar(), 'the first row is created');

// Skip: the duplicate writes nothing at all.
$pular = $validador->validar([
    ['evento' => $evA, 'nome' => $prefix . ' Gustavo Pinto Testeson', 'funcao' => 'juiz', 'documento' => $cpfDup],
], [1 => [Problema::DUPLICADO => Problema::IGNORAR]]);
$r = $gravador->gravar($pular);
T::same(0, $r->criadas, 'a skipped duplicate creates nothing');
T::same(1, $r->ignoradas, 'and is counted as skipped');
T::same(1, $contar(), 'the table is unchanged');

// Update: the existing row is rewritten, and keeps its own batch.
$atualizar = $validador->validar([
    ['evento' => $evA, 'nome' => $prefix . ' Gustavo Pinto Machado Testeson', 'funcao' => 'juiz', 'documento' => $cpfDup],
], [
    1 => [
        Problema::DUPLICADO            => Problema::ATUALIZAR,
        Problema::NOME_DIVERGENTE_LEVE => Problema::MANTER_AMBOS,
    ],
]);
$r = $gravador->gravar($atualizar);
T::same(1, $r->atualizadas, 'an updated duplicate is counted as an update');
T::same(0, $r->criadas, 'and creates no new row');
T::same(1, $contar(), 'the table still holds one row');

$atualizada = ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->findOne();
T::same($prefix . ' Gustavo Pinto Machado Testeson', $atualizada->getNome(), 'the stored name was rewritten');
T::same($loteOriginal, (string) $atualizada->getLoteId(), 'and the row keeps the batch that created it');
T::notSame($r->loteId, (string) $atualizada->getLoteId(), 'not the batch that updated it');

// That last one is the whole point: if updating restamped lote_id, undoing
// this batch would delete a certificate this batch never created.
T::same(0, count(Gravador::linhasDoLote($r->loteId)), 'the updating batch owns no rows');

// --- correcting a stored name across events -------------------------------------------

$cleanup();
$cpfNome = synthetic_cpf('909090901');
foreach ([$evA, $evB] as $ev) {
    $p = new Participante();
    $p->setNome($prefix . ' Joao Ricardo Testeson');
    $p->setFuncao('competidor');
    $p->setCpf($cpfNome);
    $p->setEventoId($ev);
    $p->setCriadoPor(test_user_id());
    $p->save();
}
T::same(2, $contar(), 'two rows on file under one document');

$corrigir = $validador->validar([
    ['evento' => $evB, 'nome' => $prefix . ' João Ricardo Testeson', 'funcao' => 'orientador', 'documento' => $cpfNome],
], [1 => [Problema::NOME_DIVERGENTE_LEVE => Problema::ATUALIZAR_NOME]]);

T::ok('the accented spelling is a minor conflict', $corrigir[0]->resolucao(Problema::NOME_DIVERGENTE_LEVE) !== null);
$r = $gravador->gravar($corrigir);

T::same(2, $r->nomesCorrigidos, 'both existing rows were rewritten');
T::same(1, $r->criadas, 'and the new row was created');
T::same(3, $contar(), 'three rows now');

// Compared in PHP, and it has to be. `nome` is latin1_swedish_ci, which is
// accent- and case-insensitive, so a WHERE on it cannot tell "Joao" from
// "João" — the assertion would pass against rows that were never rewritten.
// Every name comparison in the validator and the writer is in PHP for the
// same reason.
$antigos = 0;
foreach (ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->find() as $row) {
    if ((string) $row->getNome() === $prefix . ' Joao Ricardo Testeson') {
        $antigos++;
    }
}
T::same(0, $antigos, 'no row keeps the old spelling');

T::same(
    3,
    ParticipanteQuery::create()->filterByNome($prefix . ' Joao Ricardo Testeson')->count(),
    'and SQL cannot tell the two spellings apart — which is why the check above is in PHP'
);

// --- "use the existing name" --------------------------------------------------------

$cleanup();
$cpfExistente = synthetic_cpf('010203045');
$p = new Participante();
$p->setNome($prefix . ' Helena Márcia Testeson');
$p->setFuncao('competidor');
$p->setCpf($cpfExistente);
$p->setEventoId($evA);
$p->setCriadoPor(test_user_id());
$p->save();

$usarExistente = $validador->validar([
    ['evento' => $evB, 'nome' => $prefix . ' Helena Marcia Testeson', 'funcao' => 'competidor', 'documento' => $cpfExistente],
], [1 => [Problema::NOME_DIVERGENTE_LEVE => Problema::USAR_EXISTENTE]]);

T::same(
    $prefix . ' Helena Márcia Testeson',
    $usarExistente[0]->nome,
    'the row carries the stored name before it is written'
);
$gravador->gravar($usarExistente);

T::same(
    2,
    ParticipanteQuery::create()->filterByNome($prefix . ' Helena Márcia Testeson')->count(),
    'and the new row was written under it'
);

$cleanup();

// --- the blast radius, before it happens ----------------------------------------
//
// "Correct the stored name" is the resolution an operator reaches for
// constantly, and it rewrites certificates that are already out in the world.
// The warning has to carry the count and the events so the choice can say so
// where it is offered, not after it is taken.

$cleanup();
$cpfAlcance = synthetic_cpf('112131415');
foreach ([$evA, $evB, $evA] as $i => $ev) {
    $p = new Participante();
    $p->setNome($prefix . ' Marcos Vinicius Testeson');
    $p->setFuncao(['competidor', 'juiz', 'orientador'][$i]);
    $p->setCpf($cpfAlcance);
    $p->setEventoId($ev);
    $p->setCriadoPor(test_user_id());
    $p->save();
}

$conflito = $validador->validar([
    ['evento' => $evB, 'nome' => $prefix . ' Marcos Vinícius Testeson', 'funcao' => 'comite', 'documento' => $cpfAlcance],
])[0];

$aviso = null;
foreach ($conflito->avisos() as $problema) {
    if ($problema->codigo === Problema::NOME_DIVERGENTE_LEVE) {
        $aviso = $problema;
    }
}

T::ok('an accented correction raises the minor conflict', $aviso !== null);
T::same(3, $aviso->contexto['linhas_afetadas'], 'the warning counts every row it would rewrite');
T::same([$evB, $evA], $aviso->contexto['eventos_afetados'], 'and names every event, newest first');
T::ok('the reach is stated in words', str_contains($aviso->alcance(), '3 certificados já emitidos'));
T::ok('naming the events', str_contains($aviso->alcance(), $evA) && str_contains($aviso->alcance(), $evB));

// A row that already holds the new name is not counted — the number shown has
// to be the number that happens.
$jaCorreto = $validador->validar([
    ['evento' => $evB, 'nome' => $prefix . ' Marcos Vinicius Testeson', 'funcao' => 'comite', 'documento' => $cpfAlcance],
])[0];
$semConflito = true;
foreach ($jaCorreto->avisos() as $problema) {
    if ($problema->codigo === Problema::NOME_DIVERGENTE_LEVE) {
        $semConflito = false;
    }
}
T::ok('an identical name raises no conflict at all', $semConflito);

// And what it says is what it does.
$aplicar = $validador->validar([
    ['evento' => $evB, 'nome' => $prefix . ' Marcos Vinícius Testeson', 'funcao' => 'comite', 'documento' => $cpfAlcance],
], [1 => [Problema::NOME_DIVERGENTE_LEVE => Problema::ATUALIZAR_NOME]]);
$r = $gravador->gravar($aplicar);
T::same(3, $r->nomesCorrigidos, 'applying rewrites exactly the rows the warning counted');

$cleanup();

// =====================================================================================
// Requerimento\Gravador: one report pressed many times is one report (BAJAAPP-89)
// =====================================================================================
//
// Everything above is about the *insertion* Gravador, which writes certificates.
// What follows is about a different class with the same short name,
// Baja\Certificado\Requerimento\Gravador, which writes the reports people send
// through the public /requerimento form when something about their certificate
// is wrong. That is why it is imported under a longer name here.
//
// The bug these tests pin down: a student pressed "Enviar" six times in seven
// seconds and six identical reports were saved, each one emailing the staff.
// Gravador::registrar() now looks for an open report that this one repeats
// before saving anything. Each test below builds a submission (a Formulario,
// which is what the page builds from the posted form), hands it to
// registrar(), and then counts what ended up in the database.

use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Requerimento\Duplicata;
use Baja\Certificado\Requerimento\Formulario;
use Baja\Certificado\Requerimento\Gravador as RequerimentoGravador;
use Baja\Certificado\Requerimento\Registro;
use Baja\Model\CertificadoRequerimentoQuery;
use Propel\Runtime\Propel;

T::group('gravador/requerimento');

// Every report these tests write has a name starting with this, so that the
// cleanup below can delete exactly those rows and nothing else.
$prefixoReq = 'ZZFixtureRequerimento';
$limparReq  = static function () use ($prefixoReq): void {
    CertificadoRequerimentoQuery::create()->filterByNome($prefixoReq . '%', Criteria::LIKE)->delete();
    // Propel keeps objects it already loaded in memory. Forget them, so that
    // the next query really asks the database.
    CertificadoRequerimentoQuery::create()->clear();
    \Baja\Model\Map\CertificadoRequerimentoTableMap::clearInstancePool();
};
$limparReq();

// The list of events the form offers. The form refuses an event that is not
// in this list, so the tests pass their own short one.
$eventosReq = [$evA => 'Evento A', $evB => 'Evento B'];

// A valid "my certificate is missing" (ausente) submission, as the browser
// would post it. Each test copies this and changes only what it is about.
$ausente = [
    'caso'      => Caso::AUSENTE,
    'evento'    => $evA,
    'nome'      => $prefixoReq . ' Fulana Testeson',
    'documento' => synthetic_cpf('808080801'),
    'email'     => 'zz-fixture-fulana@example.org',
    'descricao' => 'Participei do evento e o certificado nao aparece na busca.',
    'ciente'    => '1',
];

// Turn the posted fields into a checked submission, and fail loudly if the
// test itself built an invalid one — otherwise a typo in a test would show up
// as a confusing failure somewhere further down.
$formularioReq = static function (array $campos) use ($eventosReq): Formulario {
    $formulario = new Formulario($campos, $eventosReq);
    if (!$formulario->valido()) {
        throw new \LogicException('test fixture is not a valid form: ' . implode(' | ', $formulario->erros()));
    }

    return $formulario;
};

// How many report rows with this document exist right now.
$linhasDoDocumento = static function (string $documento): int {
    \Baja\Model\Map\CertificadoRequerimentoTableMap::clearInstancePool();

    return CertificadoRequerimentoQuery::create()->filterByDocumento($documento)->count();
};

// --- the same report six times is one row -------------------------------------------

$situacoes = [];
for ($i = 0; $i < 6; $i++) {
    $situacoes[] = RequerimentoGravador::registrar($formularioReq($ausente));
}

T::same(Registro::NOVO, $situacoes[0]->situacao, 'the first submission is a new report');
T::same(1, $linhasDoDocumento($ausente['documento']), 'six submissions of the same report save one row');
for ($i = 1; $i < 6; $i++) {
    T::same(Registro::REPETIDO, $situacoes[$i]->situacao, "submission " . ($i + 1) . " is recognised as a repeat");
    T::same($situacoes[0]->protocolo(), $situacoes[$i]->protocolo(), "submission " . ($i + 1) . " is given the first report's protocol");
}
T::ok('only the first one asks for a staff alert', $situacoes[0]->avisarEquipe() && !$situacoes[1]->avisarEquipe());
T::ok('a repeat writes nothing', !$situacoes[1]->gravou());

// The same email typed with different capital letters is still the same inbox.
$maiusculas = RequerimentoGravador::registrar($formularioReq(['email' => 'ZZ-Fixture-Fulana@Example.org'] + $ausente));
T::same(Registro::REPETIDO, $maiusculas->situacao, 'an email differing only in case is the same person');

// --- the same report from a different email is kept, linked, and quiet ---------------

$outroEmail = RequerimentoGravador::registrar($formularioReq(['email' => 'zz-fixture-outro@example.org'] + $ausente));
T::same(Registro::DUPLICADO, $outroEmail->situacao, 'the same report from another email is a duplicate');
T::same(2, $linhasDoDocumento($ausente['documento']), 'and it is saved as a second row');
T::same($situacoes[0]->protocolo(), (string) $outroEmail->requerimento->getDuplicadoDe(), 'pointing at the first report');
T::ok('which does not alert staff again', !$outroEmail->avisarEquipe());
T::notSame($situacoes[0]->protocolo(), $outroEmail->protocolo(), 'and its sender is given their own protocol, not the first one');

// Pressing the button again from that second address repeats the second row,
// not the first: the protocol shown must always be the sender's own.
$outroDeNovo = RequerimentoGravador::registrar($formularioReq(['email' => 'zz-fixture-outro@example.org'] + $ausente));
T::same(Registro::REPETIDO, $outroDeNovo->situacao, 'the second address pressing again is a repeat too');
T::same($outroEmail->protocolo(), $outroDeNovo->protocolo(), 'of its own row, never of the other address\'s');

// A third address points at the original, not at the second row — the staff
// page shows one report with its duplicates under it, not a chain.
$terceiro = RequerimentoGravador::registrar($formularioReq(['email' => 'zz-fixture-terceiro@example.org'] + $ausente));
T::same($situacoes[0]->protocolo(), (string) $terceiro->requerimento->getDuplicadoDe(), 'a third address also points at the original');

// --- once the first report is closed, the same report is a new one -------------------

foreach (CertificadoRequerimentoQuery::create()->filterByDocumento($ausente['documento'])->find() as $linha) {
    $linha->setStatus('resolvido');
    $linha->save();
}
$depoisDeFechado = RequerimentoGravador::registrar($formularioReq($ausente));
T::same(Registro::NOVO, $depoisDeFechado->situacao, 'after the report is closed, sending it again opens a new one');
T::same(null, $depoisDeFechado->requerimento->getDuplicadoDe(), 'not linked to the closed one');
T::same(4, $linhasDoDocumento($ausente['documento']), 'as a new row');

// "Em análise" still counts as open: somebody is working on it.
$depoisDeFechado->requerimento->setStatus('em_analise');
$depoisDeFechado->requerimento->save();
T::same(Registro::REPETIDO, RequerimentoGravador::registrar($formularioReq($ausente))->situacao, 'a report being looked at still counts as open');

// --- different event, different case, different document: different reports ---------

$limparReq();

RequerimentoGravador::registrar($formularioReq($ausente));
$outroEvento = RequerimentoGravador::registrar($formularioReq(['evento' => $evB] + $ausente));
T::same(Registro::NOVO, $outroEvento->situacao, 'the same person missing a certificate for another event is a new report');

$outroDocumento = RequerimentoGravador::registrar($formularioReq(['documento' => synthetic_cpf('808080802')] + $ausente));
T::same(Registro::NOVO, $outroDocumento->situacao, 'another document is another person, so a new report');

// --- an event typed by hand matches regardless of case and surrounding spaces --------

$limparReq();

$digitado = [
    'evento'       => Formulario::EVENTO_OUTRO,
    'evento_texto' => 'Baja SAE Sudeste, 2009',
] + $ausente;

RequerimentoGravador::registrar($formularioReq($digitado));
$gritado = RequerimentoGravador::registrar($formularioReq(['evento_texto' => '  BAJA SAE SUDESTE, 2009 '] + $digitado));
T::same(Registro::REPETIDO, $gritado->situacao, 'a typed event matches in capitals and with spaces around it');

$outroTexto = RequerimentoGravador::registrar($formularioReq(['evento_texto' => 'Baja SAE Sul, 2010'] + $digitado));
T::same(Registro::NOVO, $outroTexto->situacao, 'a different typed event is a different report');

// A typed event and an event picked from the list are never the same report,
// even from the same person: one names an event this system knows, the other
// one it does not.
$daLista = RequerimentoGravador::registrar($formularioReq($ausente));
T::same(Registro::NOVO, $daLista->situacao, 'an event from the list does not match a typed one');

// --- reports about a certificate match on its token, and on the case ----------------

$limparReq();
ParticipanteQuery::create()->filterByNome($prefixoReq . '%', Criteria::LIKE)->delete();

// A real certificate to report on. The form only accepts a token that belongs
// to an existing certificate.
$participanteReq = new Participante();
$participanteReq->setNome($prefixoReq . ' Certificado Testeson');
$participanteReq->setCpf(synthetic_cpf('808080803'));
$participanteReq->setEventoId($evA);
$participanteReq->setFuncao('competidor');
$participanteReq->setCriadoPor(test_user_id());
$participanteReq->save();
$tokenReq = (string) $participanteReq->getToken();

$incorreto = [
    'caso'  => Caso::INCORRETO,
    'token' => $tokenReq,
    'evento' => '',
] + $ausente;

$primeiroIncorreto = RequerimentoGravador::registrar($formularioReq($incorreto));
T::same(Registro::NOVO, $primeiroIncorreto->situacao, 'the first report about a certificate is new');
T::same(Registro::REPETIDO, RequerimentoGravador::registrar($formularioReq($incorreto))->situacao, 'the same report about it again is a repeat');

// "This certificate should not exist" (indevido) is a different request from
// "this certificate is wrong" (incorreto), even about the same certificate, so
// it is saved as its own report and not as a repeat. Staff were already
// alerted about this certificate a moment ago, though, so it does not alert
// them a second time — the same rule as the next test.
$indevido = RequerimentoGravador::registrar($formularioReq(['caso' => Caso::INDEVIDO] + $incorreto));
T::same(Registro::CERTIFICADO_JA_REPORTADO, $indevido->situacao, '"should not exist" is a different report from "is wrong", about the same certificate');
T::ok('so it is written', $indevido->gravou());
T::same(null, $indevido->requerimento->getDuplicadoDe(), 'and not linked as a duplicate');

// Somebody with a different document reporting the same certificate on the
// same day: a separate row (staff decide whether it is the same person), but
// no second alert. This is what the old jaAvisadoRecentemente() did, kept.
$outraPessoa = RequerimentoGravador::registrar($formularioReq(['documento' => synthetic_cpf('808080804'), 'email' => 'zz-fixture-colega@example.org'] + $incorreto));
T::same(Registro::CERTIFICADO_JA_REPORTADO, $outraPessoa->situacao, 'another document reporting the same certificate is saved');
T::same(null, $outraPessoa->requerimento->getDuplicadoDe(), 'not linked, because it may be somebody else');
T::ok('and does not alert staff again', !$outraPessoa->avisarEquipe());

// --- the lock name gives nothing away ------------------------------------------------

$trava = Duplicata::nomeDaTrava($formularioReq($ausente));
T::ok('the lock name does not contain the document', !str_contains($trava, $ausente['documento']));
T::ok('the lock name fits MySQL\'s 64-character limit', strlen($trava) <= 64, (string) strlen($trava));
T::same($trava, Duplicata::nomeDaTrava($formularioReq(['email' => 'outro@example.org', 'descricao' => 'Outra descricao qualquer, longa o bastante.'] + $ausente)),
    'two submissions of the same report wait for the same lock');
T::notSame($trava, Duplicata::nomeDaTrava($formularioReq(['evento' => $evB] + $ausente)), 'a different report waits for a different lock');

// --- if the lock cannot be taken, the report is still saved --------------------------

$limparReq();

// Hold the lock from a second, separate database connection, the way another
// request in the middle of saving this report would.
$outraConexao = new \PDO(
    'mysql:host=' . getenv('PHPBB_DB_HOST') . ';dbname=baja_resultados',
    (string) getenv('PHPBB_DB_USER'),
    (string) getenv('PHPBB_DB_PASS')
);
$outraConexao->prepare('SELECT GET_LOCK(?, 0)')->execute([$trava]);

// Wait at most one second for it rather than the usual five, to keep the test quick.
$semTrava = RequerimentoGravador::registrar($formularioReq($ausente), 1);
T::same(Registro::NOVO, $semTrava->situacao, 'a report whose lock is held by someone else is still saved');
T::same(1, $linhasDoDocumento($ausente['documento']), 'exactly once');

$outraConexao->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
$outraConexao = null;

// --- six presses at the same instant are still one row -------------------------------
//
// Everything above runs one submission after another. The real bug happened
// with requests arriving together, so here six separate PHP processes are
// started and told to submit at the same moment, a second and a half from now
// (time enough for all six to start up).
// Without the lock, all six would look for an open report before any of them
// had saved one, find none, and save six.

$limparReq();

$inicio    = microtime(true) + 1.5;
$saidas    = [];
$processos = [];
for ($i = 0; $i < 6; $i++) {
    $processos[] = proc_open(
        [PHP_BINARY, __DIR__ . '/requerimento_concorrente.php', sprintf('%.6F', $inicio), json_encode($ausente), json_encode($eventosReq)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saidas[$i] = $pipes;
}

$respostas = [];
foreach ($processos as $i => $processo) {
    $respostas[] = trim(stream_get_contents($saidas[$i][1]));
    $erro = trim(stream_get_contents($saidas[$i][2]));
    proc_close($processo);
    if ($erro !== '' && !str_contains($erro, 'could not take lock')) {
        T::ok("concurrent submission $i ran cleanly", false, $erro);
    }
}
sort($respostas);

T::same(1, $linhasDoDocumento($ausente['documento']), 'six simultaneous submissions save one row');
T::same(
    [Registro::NOVO, Registro::REPETIDO, Registro::REPETIDO, Registro::REPETIDO, Registro::REPETIDO, Registro::REPETIDO],
    $respostas,
    'one of them is the new report, the other five are repeats of it'
);

$limparReq();
ParticipanteQuery::create()->filterByNome($prefixoReq . '%', Criteria::LIKE)->delete();

// --- the retention script still works with the duplicate link ------------------------
//
// scripts/purge-certificado-requerimentos.php deletes closed reports once they
// are old enough. Now that a report can point at another one (duplicado_de),
// deleting the report being pointed at must neither fail nor take the open
// duplicate with it: the duplicate should just stop pointing anywhere.
//
// The fixtures are dated far in the past (about eight years) and the script is
// told to keep anything younger than that, so it cannot touch a real report.

T::group('gravador/requerimento-purge');

$limparReq();

$fecharEnvelhecer = static function ($requerimento): void {
    $requerimento->setStatus('resolvido');
    $requerimento->setResolvidoEm(new \DateTime('-3000 days'));
    $requerimento->save();
};

// An original that was closed long ago, with an open duplicate still in the queue.
$originalVelho = RequerimentoGravador::registrar($formularioReq($ausente))->requerimento;
$duplicadoAberto = RequerimentoGravador::registrar($formularioReq(['email' => 'zz-fixture-purge-a@example.org'] + $ausente))->requerimento;
T::same($originalVelho->getRequerimentoId(), $duplicadoAberto->getDuplicadoDe(), 'fixture: the duplicate points at the original');
$fecharEnvelhecer($originalVelho);

// And a second pair where BOTH are closed and old, so one DELETE removes a
// report and the report pointing at it together.
$outroAusente = ['documento' => synthetic_cpf('808080805')] + $ausente;
$par1 = RequerimentoGravador::registrar($formularioReq($outroAusente))->requerimento;
$par2 = RequerimentoGravador::registrar($formularioReq(['email' => 'zz-fixture-purge-b@example.org'] + $outroAusente))->requerimento;
$fecharEnvelhecer($par1);
$fecharEnvelhecer($par2);

exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/purge-certificado-requerimentos.php') . ' --days=2900 2>&1',
    $saidaPurge,
    $codigoPurge
);
T::same(0, $codigoPurge, 'the purge script runs cleanly', implode("\n", $saidaPurge));

\Baja\Model\Map\CertificadoRequerimentoTableMap::clearInstancePool();
T::same(null, CertificadoRequerimentoQuery::create()->findPk($originalVelho->getRequerimentoId()), 'the old closed original was deleted');
$sobrevivente = CertificadoRequerimentoQuery::create()->findPk($duplicadoAberto->getRequerimentoId());
T::ok('its open duplicate was kept', $sobrevivente !== null);
T::same(null, $sobrevivente?->getDuplicadoDe(), 'and no longer points anywhere');
T::same(0, CertificadoRequerimentoQuery::create()->filterByRequerimentoId([$par1->getRequerimentoId(), $par2->getRequerimentoId()], Criteria::IN)->count(),
    'a closed original and its closed duplicate are deleted together');

$limparReq();
