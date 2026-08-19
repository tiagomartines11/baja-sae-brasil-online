<?php

use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Lotes;
use Baja\Certificado\Insercao\Revisao;
use Baja\Certificado\Insercao\Validador;
use Baja\Model\EventoQuery;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('lotes');

if (!test_db_available()) {
    T::skip('batch listing tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventos = EventoQuery::create()->orderByEventoId()->limit(2)->find();
if (count($eventos) < 2) {
    T::skip('batch listing tests', 'need at least two events');
    return;
}
$evA = $eventos[0]->getEventoId();
$evB = $eventos[1]->getEventoId();

$prefix  = 'ZZFixtureLotes';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();
};
$cleanup();

$validador = new Validador();
$gravador  = new Gravador(test_user_id());

$antes = (new Lotes())->total();

/** @return string the batch id */
$criar = static function (array $linhas) use ($validador, $gravador): string {
    return $gravador->gravar((new Revisao($validador->validar($linhas)))->prontas())->loteId;
};

// A batch spanning two events, and a batch of one.
$loteGrande = $criar([
    ['evento' => $evA, 'nome' => $prefix . ' Ana Beatriz Testeson',  'funcao' => 'competidor', 'documento' => synthetic_cpf('212121212')],
    ['evento' => $evA, 'nome' => $prefix . ' Bruno Carlos Testeson', 'funcao' => 'juiz',       'documento' => synthetic_cpf('232323232')],
    ['evento' => $evB, 'nome' => $prefix . ' Carla Duarte Testeson', 'funcao' => 'comite',     'documento' => synthetic_cpf('242424242')],
]);
$lotePequeno = $criar([
    ['evento' => $evB, 'nome' => $prefix . ' Diego Esteves Testeson', 'funcao' => 'juiz', 'documento' => synthetic_cpf('252525252')],
]);

T::same($antes + 2, (new Lotes())->total(), 'two new batches are listed');

$porId = static function (array $pagina, string $id): ?array {
    foreach ($pagina as $linha) {
        if ($linha['id'] === $id) {
            return $linha;
        }
    }

    return null;
};

$grande = $porId((new Lotes())->pagina(1), $loteGrande);
T::ok('the batch is on the first page', $grande !== null);
T::same(3, $grande['linhas'], 'with its row count');
T::same(0, $grande['anuladas'], 'and none voided yet');
T::same([$evA, $evB], $grande['eventos'], 'and every event it touches, in order');
T::same([test_user_id()], $grande['autores'], 'and who created it');
T::ok('and when', $grande['criado_em'] !== null);

// --- newest first ------------------------------------------------------------------

$ids = array_column((new Lotes())->pagina(1), 'id');
T::ok('both batches are listed', in_array($loteGrande, $ids, true) && in_array($lotePequeno, $ids, true));

// The order has to be total, not merely newest-first. criado_em is a DATETIME,
// so two batches created in the same second — which these two were — sort
// equally, and without a tiebreaker MySQL may return them either way round
// between one page request and the next. That is how a batch gets shown twice
// or skipped entirely while somebody pages through.
$repetido = array_column((new Lotes())->pagina(1), 'id');
T::same($ids, $repetido, 'the same query twice gives the same order');

// And within a second, the tiebreaker decides.
$mesmoSegundo = array_values(array_filter($ids, static fn (string $id): bool => in_array($id, [$loteGrande, $lotePequeno], true)));
$esperado     = [$loteGrande, $lotePequeno];
rsort($esperado);
T::same($esperado, $mesmoSegundo, 'batches sharing a timestamp fall back to their id');

// --- filtering by event picks batches, never trims their counts -----------------------
//
// This is the reason the filter runs as its own query. Filtering the rows and
// then grouping them would report the three-row batch as having one row when
// narrowed to the event that holds one.

$soB = new Lotes([$evB]);
T::same(2, $soB->total(), 'both batches have a row in the second event');

$grandeFiltrado = $porId($soB->pagina(1), $loteGrande);
T::same(3, $grandeFiltrado['linhas'], 'and the batch still reports all three of its rows');
T::same([$evA, $evB], $grandeFiltrado['eventos'], 'and still names both of its events');

$soA = new Lotes([$evA]);
T::same(1, $soA->total(), 'only one batch touches the first event');
T::same($loteGrande, $soA->pagina(1)[0]['id'], 'and it is the right one');

// --- the other filters ------------------------------------------------------------------

T::same(2, (new Lotes([], test_user_id()))->total(), 'filtering by author finds both');
T::same(0, (new Lotes([], 999999))->total(), 'an author who created nothing finds none');

T::same(1, (new Lotes([], 0, $loteGrande))->total(), 'the whole batch id finds it');
T::same(1, (new Lotes([], 0, substr($loteGrande, 0, 8)))->total(), 'and so does a fragment');
T::same(0, (new Lotes([], 0, 'zzzzzzzz'))->total(), 'a fragment matching nothing finds nothing');

// LIKE metacharacters must not widen the search, here as anywhere else.
//
// A percent never appears in a batch id, so searching one finds nothing. An
// underscore is a different matter: base64url uses it, so some ids contain one
// and searching it legitimately finds those. What must not happen is `_`
// behaving as LIKE's single-character wildcard and matching every batch —
// which is what an earlier version of this check mistook for a failure when it
// asserted a flat zero and the generated ids happened to contain an
// underscore.
T::same(0, (new Lotes([], 0, '%'))->total(), 'a literal percent is not a wildcard');

// Deterministic, and built from an id this test owns: take the batch's own
// identifier and replace one character with an underscore. Matched literally
// that string belongs to no batch and finds nothing; treated as LIKE's
// single-character wildcard it would match the batch it came from.
$posicao = null;
for ($i = 0; $i < strlen($loteGrande); $i++) {
    if ($loteGrande[$i] !== '_') {
        $posicao = $i;
        break;
    }
}
T::ok('the batch id has a character to disguise', $posicao !== null);

$disfarcado = substr_replace($loteGrande, '_', $posicao, 1);
T::notSame($loteGrande, $disfarcado, 'the disguised id differs from the real one');
T::same(1, (new Lotes([], 0, $loteGrande))->total(), 'the real id still finds its batch');
T::same(0, (new Lotes([], 0, $disfarcado))->total(), 'an underscore matches literally, not as a wildcard');

// Filters narrow rather than widen when combined.
T::same(0, (new Lotes([$evA], 0, $lotePequeno))->total(), 'combined filters intersect');

T::ok('an unfiltered listing knows it is unfiltered', !(new Lotes())->temFiltro());
T::ok('and a filtered one knows it is filtered', (new Lotes([$evA]))->temFiltro());

// --- voided rows are counted, not hidden --------------------------------------------------
//
// A batch whose certificates were voided is exactly what somebody comes here
// looking for, so it stays listed and says how many.

$tokens = [];
foreach (Gravador::linhasDoLote($loteGrande) as $linha) {
    $tokens[] = (string) $linha->getToken();
}
(new \Baja\Certificado\Insercao\Anulacao(test_user_id()))->anular([$tokens[0]], 'teste');

$comAnulada = $porId((new Lotes())->pagina(1), $loteGrande);
T::same(3, $comAnulada['linhas'], 'the batch still reports every row it created');
T::same(1, $comAnulada['anuladas'], 'and how many of them are void');

// --- pages ---------------------------------------------------------------------------------

$total = (new Lotes())->total();
T::same(1, (new Lotes())->paginas($total), 'a handful of batches is one page');
T::same(2, (new Lotes())->paginas(Lotes::POR_PAGINA + 1), 'one over the cap needs a second');
T::same(1, (new Lotes())->paginas(0), 'and nothing still reports one page');
T::same(
    count((new Lotes())->pagina(1)),
    count((new Lotes())->pagina(0)),
    'a page number below one is read as the first'
);

// --- authors, and rows that belong to no batch ------------------------------------------------

T::ok('the author filter offers whoever created a batch', isset(Lotes::autores()[test_user_id()]));
T::same([], Lotes::nomesDeUsuario([]), 'no ids, no names');
T::same([], Lotes::nomesDeUsuario([0]), 'and a zero id is not a user');

// Rows predating the audit columns have no batch, and the page says how many so
// the numbers reconcile rather than appearing to lose certificates.
$semLote = Lotes::semLote();
T::ok('rows with no batch are counted separately', $semLote >= 0);
T::same(
    0,
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)
        ->filterByLoteId(null, Criteria::ISNULL)->count(),
    'nothing created through these pages lands without one'
);

$cleanup();
