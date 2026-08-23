<?php

use Baja\Certificado\Insercao\Linha;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Revisao;
use Baja\Certificado\Insercao\Validador;
use Baja\Model\EventoQuery;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('revisao');

if (!test_db_available()) {
    T::skip('review tests', 'BAJA_TEST_DB is not 1');
    return;
}

$evento = EventoQuery::create()->findOne();
if (!$evento) {
    T::skip('review tests', 'no events');
    return;
}
$ev = $evento->getEventoId();

$prefix = 'ZZFixtureRevisao';
ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();

$validador = new Validador();

$brutas = [
    // OK
    ['evento' => $ev, 'nome' => $prefix . ' Ana Beatriz Testeson', 'funcao' => 'competidor', 'documento' => synthetic_cpf('123123123')],
    // warning: deprecated funcao
    ['evento' => $ev, 'nome' => $prefix . ' Bruno Carlos Testeson', 'funcao' => 'fiscal', 'documento' => synthetic_cpf('234234234')],
    // error: unknown funcao
    ['evento' => $ev, 'nome' => $prefix . ' Carla Duarte Testeson', 'funcao' => 'inventada', 'documento' => synthetic_cpf('345345345')],
    // error: scientific notation
    ['evento' => $ev, 'nome' => $prefix . ' Diego Esteves Testeson', 'funcao' => 'juiz', 'documento' => '1.23457E+10'],
    // warning: deprecated funcao again, so the group has two rows
    ['evento' => $ev, 'nome' => $prefix . ' Elena Faria Testeson', 'funcao' => 'engenheiro', 'documento' => synthetic_cpf('456456456')],
];

$revisao = new Revisao($validador->validar($brutas));

T::same(2, count($revisao->erros), 'two rows have errors');
T::same(2, count($revisao->avisos), 'two rows need a decision');
T::same(1, count($revisao->ok), 'one row is clean');
T::same(5, count($revisao->linhas), 'and every row is in exactly one bucket');

T::ok('an unanswered warning blocks the commit', !$revisao->podeGravar());
T::same(2, count($revisao->pendentes()), 'both warnings are pending');

// Answering the warnings is not enough while an error remains. Errors are
// fixed in the spreadsheet, not here.
$respondido = new Revisao($validador->validar($brutas, [
    2 => [Problema::FUNCAO_OBSOLETA => Problema::CONFIRMAR],
    5 => [Problema::FUNCAO_OBSOLETA => Problema::CONFIRMAR],
]));
T::same(0, count($respondido->pendentes()), 'the warnings are answered');
T::ok('but the errors still block the commit', !$respondido->podeGravar());

// --- grouping -----------------------------------------------------------------

$grupos = $revisao->agrupados();
T::same([Problema::FUNCAO_OBSOLETA], array_keys($grupos), 'one distinct warning across the batch');
T::same(2, $grupos[Problema::FUNCAO_OBSOLETA]['linhas'], 'carried by two rows');
T::same(
    'Função que não é mais usada',
    Revisao::rotuloGrupo(Problema::FUNCAO_OBSOLETA),
    'the group has a name of its own, not the per-row message'
);

// --- a batch with nothing wrong -------------------------------------------------

$limpas = new Revisao($validador->validar([
    ['evento' => $ev, 'nome' => $prefix . ' Ana Beatriz Testeson',  'funcao' => 'competidor', 'documento' => synthetic_cpf('123123123')],
    ['evento' => $ev, 'nome' => $prefix . ' Bruno Carlos Testeson', 'funcao' => 'juiz',       'documento' => synthetic_cpf('234234234')],
]));
T::ok('a clean batch can be committed', $limpas->podeGravar());
T::same(2, $limpas->aCriar(), 'and would create both rows');
T::same(0, $limpas->aIgnorar(), 'skipping nothing');
T::same([], $limpas->agrupados(), 'with no warning groups');

// --- an empty batch is not a commit ------------------------------------------------
//
// Zero rows satisfies "every row is ready" vacuously, and a button that says
// "create 0 certificates" and succeeds is worse than one that refuses.

T::ok('an empty batch cannot be committed', !(new Revisao([]))->podeGravar());

// --- skipped rows are counted apart ------------------------------------------------

$cpf = synthetic_cpf('567567567');
$p = new \Baja\Model\Participante();
$p->setNome($prefix . ' Fabio Gomes Testeson');
$p->setFuncao('competidor');
$p->setCpf($cpf);
$p->setEventoId($ev);
$p->setCriadoPor(test_user_id());
$p->save();

$comPulo = new Revisao($validador->validar([
    ['evento' => $ev, 'nome' => $prefix . ' Fabio Gomes Testeson', 'funcao' => 'competidor', 'documento' => $cpf],
    ['evento' => $ev, 'nome' => $prefix . ' Gilda Horta Testeson', 'funcao' => 'competidor', 'documento' => synthetic_cpf('678678678')],
], [1 => [Problema::DUPLICADO => Problema::IGNORAR]]));

T::ok('the batch is ready', $comPulo->podeGravar());
T::same(1, $comPulo->aCriar(), 'a skipped duplicate is not counted as something to create');
T::same(1, $comPulo->aIgnorar(), 'it is counted as a skip');
T::same(2, count($comPulo->linhas), 'and it is still one of the rows');

ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();
