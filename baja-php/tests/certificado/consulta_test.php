<?php

use Baja\Certificado\Insercao\Consulta;
use Baja\Model\EventoQuery;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('consulta');

if (!test_db_available()) {
    T::skip('lookup tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventos = EventoQuery::create()->orderByEventoId()->limit(2)->find();
if (count($eventos) < 2) {
    T::skip('lookup tests', 'need at least two events');
    return;
}
$evA = $eventos[0]->getEventoId();
$evB = $eventos[1]->getEventoId();

$prefix  = 'ZZFixtureConsulta';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();
};
$cleanup();

$gravar = static function (string $nome, ?string $cpf, ?string $est, string $ev, string $funcao) use ($prefix): void {
    $p = new Participante();
    $p->setNome($prefix . ' ' . $nome);
    $p->setCpf($cpf);
    $p->setDocumentoEstrangeiro($est);
    $p->setEventoId($ev);
    $p->setFuncao($funcao);
    $p->setCriadoPor(test_user_id());
    $p->save();
};

$gravar('José Antônio da Silva', synthetic_cpf('529982247'), null, $evA, 'competidor');
$gravar("Maria D\u{2019}Ávila Souza", null, 'AB-123.456', $evA, 'juiz');
$gravar('Pedro Silva Lima',      synthetic_cpf('001234567'), null, $evB, 'comite');
$gravar('Ana Clara Nogueira',    null, 'XY 987 654', $evB, 'fiscal');

/** @return array<int, string> names found, prefix stripped, sorted */
$nomes = static function (Consulta $c) use ($prefix): array {
    $out = [];
    foreach ($c->pagina(1) as $linha) {
        $out[] = trim(str_replace($prefix, '', (string) $linha->getNome()));
    }
    sort($out);

    return $out;
};

// --- name: accents, case and apostrophes are all ignored -----------------------------
//
// The first two come free from the column's collation, which is
// latin1_swedish_ci. Only the apostrophe needs doing, on both sides.

T::same(['José Antônio da Silva'], $nomes(new Consulta([], [], $prefix . ' Jose*Silva')), 'a wildcard between two parts');
T::same(['José Antônio da Silva'], $nomes(new Consulta([], [], $prefix . ' JOSE*silva')), 'case is ignored');
T::same(['José Antônio da Silva'], $nomes(new Consulta([], [], $prefix . ' José*Silva')), 'accents in the term are ignored too');
T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix . '*DAvila')), 'an apostrophe in the row is ignored');
T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix . "*D'Avila")), 'and one in the term');
T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix . "*D\u{2019}avila")), 'including a curly one');

T::same(
    ['José Antônio da Silva', 'Pedro Silva Lima'],
    $nomes(new Consulta([], [], $prefix . '*Silva')),
    'a bare surname matches as a substring'
);

// --- documents ------------------------------------------------------------------------

$cpfJose = synthetic_cpf('529982247');
T::same(['José Antônio da Silva'], $nomes(new Consulta([], [], $prefix, $cpfJose, Consulta::DOC_CPF)), 'a whole CPF');
T::same(['José Antônio da Silva'], $nomes(new Consulta([], [], $prefix, '529.982.247-25', Consulta::DOC_CPF)), 'punctuated');
T::same(['José Antônio da Silva'], $nomes(new Consulta([], [], $prefix, '5299', Consulta::DOC_CPF)), 'a fragment');

// A CPF whose leading zeros a spreadsheet ate still finds its padded row,
// because the match is a substring.
T::same(
    ['Pedro Silva Lima'],
    $nomes(new Consulta([], [], $prefix, ltrim(synthetic_cpf('001234567'), '0'), Consulta::DOC_CPF)),
    'a CPF missing its leading zeros finds the padded row'
);

T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix, 'AB-123.456', Consulta::DOC_PASSAPORTE)), 'a punctuated passport');
T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix, 'ab 123 456', Consulta::DOC_PASSAPORTE)), 'punctuated differently, and lowercase');
T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix, 'AB*456', Consulta::DOC_PASSAPORTE)), 'with a wildcard');
T::same(['Ana Clara Nogueira'], $nomes(new Consulta([], [], $prefix, 'XY987654', Consulta::DOC_PASSAPORTE)), 'the other passport');

// --- the cpf / passport / both selector ------------------------------------------------
//
// One term, and which columns it is allowed to hit.

$ambos = $nomes(new Consulta([], [], $prefix, '123456', Consulta::DOC_AMBOS));
T::same(["Maria D\u{2019}Ávila Souza", 'Pedro Silva Lima'], $ambos, 'both columns answer one term');
T::same(['Pedro Silva Lima'], $nomes(new Consulta([], [], $prefix, '123456', Consulta::DOC_CPF)), 'CPF only excludes the passport row');
T::same(["Maria D\u{2019}Ávila Souza"], $nomes(new Consulta([], [], $prefix, '123456', Consulta::DOC_PASSAPORTE)), 'passport only excludes the CPF row');

// A term the chosen column cannot use matches nothing, and specifically does
// not quietly become "no document filter" — that would answer a question
// nobody asked by returning more rows than were expected, which is the worst
// direction for this kind of mistake to go.
$inutil = new Consulta([], [], $prefix, 'AB', Consulta::DOC_CPF);
T::same([], $nomes($inutil), 'letters restricted to CPF match nothing');
T::same(0, $inutil->total(), 'and the count agrees');
T::ok('the search knows the term was unusable', $inutil->documentoImpossivel());
T::ok('and still counts as a filter, so the page can say why', $inutil->temFiltro());

$util = new Consulta([], [], $prefix, 'AB', Consulta::DOC_PASSAPORTE);
T::ok('the same term against the passport column is fine', !$util->documentoImpossivel());

// --- events and roles, one or several ----------------------------------------------------

T::same(2, (new Consulta([$evA], [], $prefix))->total(), 'one event');
T::same(4, (new Consulta([$evA, $evB], [], $prefix))->total(), 'two events');
T::same(1, (new Consulta([], ['juiz'], $prefix))->total(), 'one role');
T::same(2, (new Consulta([], ['juiz', 'comite'], $prefix))->total(), 'two roles');
T::same(1, (new Consulta([$evA], ['juiz'], $prefix))->total(), 'event and role together');
T::same(0, (new Consulta([$evB], ['juiz'], $prefix))->total(), 'and they narrow rather than widen');

// A deprecated role is searchable even though it cannot be chosen for a new
// record — the certificates carrying it exist and are exactly what somebody
// would be looking for.
T::same(['Ana Clara Nogueira'], $nomes(new Consulta([], ['fiscal'], $prefix)), 'a deprecated role is still searchable');

// --- everything together -----------------------------------------------------------------

T::same(
    ['José Antônio da Silva'],
    $nomes(new Consulta([$evA], ['competidor'], $prefix . '*Silva', '5299', Consulta::DOC_CPF)),
    'all four filters at once'
);

// --- no filter is a legitimate thing to ask ------------------------------------------------

$tudo = new Consulta();
T::ok('an unfiltered lookup is not a filter', !$tudo->temFiltro());
T::ok('and returns the table', $tudo->total() >= 4);
T::ok('a name term counts as a filter', (new Consulta([], [], 'x'))->temFiltro());
T::ok('so does an event', (new Consulta([$evA]))->temFiltro());
T::ok('so does a document', (new Consulta([], [], '', '123'))->temFiltro());
T::ok('but a lone wildcard does not', !(new Consulta([], [], '*', '*'))->temFiltro());

// --- LIKE metacharacters cannot widen a search ---------------------------------------------

$gravar('Desconto 100% Teste', null, 'PCT100', $evA, 'juiz');
T::same(['Desconto 100% Teste'], $nomes(new Consulta([], [], $prefix . '*100%')), 'a literal percent matches only itself');
T::ok('and does not match everything', (new Consulta([], [], $prefix . '*100%'))->total() === 1);

// --- pages -----------------------------------------------------------------------------------

$total = (new Consulta([], [], $prefix))->total();
T::same(1, (new Consulta([], [], $prefix))->paginas($total), 'five rows fit on one page');
T::same(2, (new Consulta())->paginas(Consulta::POR_PAGINA + 1), 'one row over needs a second page');
T::same(1, (new Consulta())->paginas(Consulta::POR_PAGINA), 'exactly a page needs only one');
T::same(1, (new Consulta())->paginas(0), 'and nothing at all still reports one page');

$primeira = (new Consulta([], [], $prefix))->pagina(1);
$segunda  = (new Consulta([], [], $prefix))->pagina(2);
T::same(5, count($primeira), 'the first page holds every row there is');
T::same(0, count($segunda), 'and the second holds none');
T::same(
    count($primeira),
    count((new Consulta([], [], $prefix))->pagina(0)),
    'a page number below one is read as the first'
);

$cleanup();
