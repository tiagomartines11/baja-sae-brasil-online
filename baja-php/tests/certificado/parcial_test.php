<?php

use Baja\Certificado\Insercao\Exportacao;
use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Mapeamento;
use Baja\Certificado\Insercao\Planilha;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Revisao;
use Baja\Certificado\Insercao\Validador;
use Baja\Certificado\Token;
use Baja\Model\EventoQuery;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('parcial');

if (!test_db_available()) {
    T::skip('partial commit tests', 'BAJA_TEST_DB is not 1');
    return;
}

$evento = EventoQuery::create()->findOne();
if (!$evento) {
    T::skip('partial commit tests', 'no events');
    return;
}
$ev = $evento->getEventoId();

$prefix  = 'ZZFixtureParcial';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();
};
$cleanup();

$validador = new Validador();
$gravador  = new Gravador(test_user_id());
$mapa      = new Mapeamento([0 => 'evento', 1 => 'nome', 2 => 'funcao', 3 => 'cpf', 4 => 'passaporte']);

$celulas = [
    [$ev, $prefix . ' Ana Beatriz Testeson',  'competidor', synthetic_cpf('313131313'), ''],
    [$ev, $prefix . ' Bruno Carlos Testeson', 'juiz',       '',                          'AB999111'],
    [$ev, $prefix . ' Carla Duarte Testeson', 'fiscal',     synthetic_cpf('414141414'), ''],
    [$ev, $prefix . ' Diego Esteves Testeson', 'inventada', synthetic_cpf('515151515'), ''],
];
$brutas = array_map(static fn (array $c): array => $mapa->aplicar($c), $celulas);

$revisao = new Revisao($validador->validar($brutas));

// --- what a partial commit would split ---------------------------------------------

T::same(2, count($revisao->prontas()), 'two rows are ready');
T::same(2, count($revisao->naoProntas()), 'two are not');
T::same(2, $revisao->aCriarProntas(), 'and two would be created');
T::ok('so a partial commit is worth offering', $revisao->podeGravarParcial());
T::ok('while a whole one is refused', !$revisao->podeGravar());

// Errors and unanswered warnings land in the same pile: from the operator's
// side both mean "I cannot deal with this one now".
$numeros = array_map(static fn ($l) => $l->numero, $revisao->naoProntas());
T::same([3, 4], $numeros, 'the deprecated funcao and the unknown one both wait');

// Nothing to offer when everything is ready, and nothing when nothing is.
$todasOk = new Revisao($validador->validar([$brutas[0], $brutas[1]]));
T::ok('a clean batch offers no partial commit', !$todasOk->podeGravarParcial());
T::ok('it just commits', $todasOk->podeGravar());

$todasRuins = new Revisao($validador->validar([$brutas[3]]));
T::ok('a batch with nothing ready offers no partial commit either', !$todasRuins->podeGravarParcial());

// --- committing the ready half ------------------------------------------------------

$resultado = $gravador->gravar($revisao->prontas());
T::same(2, $resultado->criadas, 'the ready rows are created');
T::same(2, ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->count(), 'and only those');
T::same(2, count(Gravador::linhasDoLote($resultado->loteId)), 'as one identifiable batch');

// --- the leftovers come back out as a sheet -------------------------------------------

$tsv = Exportacao::tsv($revisao->naoProntas());
T::same(2, count(explode("\n", trim($tsv))), 'two rows exported');

// And parse back to exactly what went in, under the mapping the page hands over.
$relido = Planilha::analisar($tsv);
T::same(2, count($relido->linhas), 'the export parses back');

$mapaExport = new Mapeamento(Exportacao::mapeamento());
T::ok('the export mapping is complete on its own', $mapaExport->valido());

$voltaram = $validador->validar(array_map(
    static fn (array $c): array => $mapaExport->aplicar($c),
    $relido->linhas
));

T::same($prefix . ' Carla Duarte Testeson', $voltaram[0]->nomeBruto, 'the first leftover keeps its name');
T::same(synthetic_cpf('414141414'), $voltaram[0]->cpf, 'and its CPF, still in the CPF column');
T::ok('and still raises exactly the warning it did before', in_array(
    Problema::FUNCAO_OBSOLETA,
    array_map(static fn ($p) => $p->codigo, $voltaram[0]->problemas()),
    true
));
T::ok('the second still errors on its funcao', in_array(
    Problema::FUNCAO_DESCONHECIDA,
    array_map(static fn ($p) => $p->codigo, $voltaram[1]->problemas()),
    true
));

// --- the export keeps a passport in the passport column --------------------------------
//
// This is why the export is five columns rather than four. A digits-only
// passport pushed through a generic "documento" column would come back
// ambiguous, and the operator would be asked a question the original paste had
// already answered.

$soPassaporte = new Revisao($validador->validar([
    $mapa->aplicar([$ev, $prefix . ' Elena Faria Testeson', 'inventada', '', '00987654']),
]));
$tsvPassaporte = Exportacao::tsv($soPassaporte->naoProntas());
T::same("$ev\t{$prefix} Elena Faria Testeson\tinventada\t\t00987654\n", $tsvPassaporte, 'the passport stays in its own column');

$reliday = $validador->validar(array_map(
    static fn (array $c): array => $mapaExport->aplicar($c),
    Planilha::analisar($tsvPassaporte)->linhas
));
T::same('00987654', $reliday[0]->documentoEstrangeiro, 'so it comes back as a foreign document');
T::ok('and raises no ambiguity', !in_array(
    Problema::DOCUMENTO_AMBIGUO,
    array_map(static fn ($p) => $p->codigo, $reliday[0]->problemas()),
    true
));

// A row that filled both columns keeps both values, so the sheet the operator
// gets back holds everything they pasted.
$ambos = new Revisao($validador->validar([
    $mapa->aplicar([$ev, $prefix . ' Fabio Gomes Testeson', 'juiz', synthetic_cpf('616161616'), 'AB777222']),
]));
$tsvAmbos = Exportacao::tsv($ambos->naoProntas());
T::ok('the CPF is in the export', str_contains($tsvAmbos, synthetic_cpf('616161616')));
T::ok('and so is the passport', str_contains($tsvAmbos, 'AB777222'));

// --- a cell containing a tab survives the round trip ------------------------------------

$comTab = new Revisao($validador->validar([[
    'evento' => $ev, 'nome' => $prefix . " Gil\tHorta Testeson", 'funcao' => 'inventada', 'documento' => '1',
]]));
$tsvTab = Exportacao::tsv($comTab->naoProntas());
T::ok('a cell holding a tab is quoted', str_contains($tsvTab, '"'));
$relidoTab = Planilha::analisar($tsvTab);
T::same(1, count($relidoTab->linhas), 'so the export is still one row');
T::same($prefix . " Gil\tHorta Testeson", $relidoTab->linhas[0][1], 'and the tab is still inside the cell');

// --- the batch id makes a resubmitted commit recognisable ---------------------------------
//
// A browser sends the same POST again when somebody presses F5 on the result.
// Content cannot tell us that is what happened — pasting the same sheet twice
// for two different events is legitimate — but the batch id can, because it
// belongs to one rendering of one form.

$cleanup();
$escolhido = Token::generate();
T::ok('an unused batch id is unused', !Gravador::loteExiste($escolhido));

$prontas = (new Revisao($validador->validar([$brutas[0], $brutas[1]])))->prontas();
$r = $gravador->gravar($prontas, $escolhido);
T::same($escolhido, $r->loteId, 'the caller-supplied batch id is the one used');
T::ok('and is now taken', Gravador::loteExiste($escolhido));
T::same(2, count(Gravador::linhasDoLote($escolhido)), 'with its rows under it');

T::ok('a malformed id is never considered taken', !Gravador::loteExiste('nao-e-um-lote'));

// Without an id, one is generated, so nothing is coupled to the caller
// remembering to pass one.
$semId = $gravador->gravar(
    (new Revisao($validador->validar([
        $mapa->aplicar([$ev, $prefix . ' Helena Ibere Testeson', 'juiz', synthetic_cpf('717171717'), '']),
    ])))->prontas()
);
T::ok('a generated batch id is well formed', Token::isWellFormed($semId->loteId));
T::notSame($escolhido, $semId->loteId, 'and is not the previous one');

$cleanup();
