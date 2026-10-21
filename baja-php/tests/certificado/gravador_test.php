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
$cleanup();
$naoResolvida = $validador->validar([
    ['evento' => $evA, 'nome' => $prefix . ' Fabio Lima Testeson', 'funcao' => 'fiscal', 'documento' => synthetic_cpf('777888999')],
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
