<?php

use Baja\Certificado\Insercao\Gravador;
use Baja\Certificado\Insercao\Lotes;
use Baja\Certificado\Insercao\Revisao;
use Baja\Certificado\Insercao\Validador;
use Baja\Model\EventoQuery;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;

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
// so two batches created in the same second sort equally, and without a
// tiebreaker MySQL may return them either way round between one page request
// and the next. That is how a batch gets shown twice or skipped entirely
// while somebody pages through.
$repetido = array_column((new Lotes())->pagina(1), 'id');
T::same($ids, $repetido, 'the same query twice gives the same order');

// What the tiebreaker does when two batches DO share a second is checked at
// the end of this file, with batch ids this test picks itself.
//
// It used to be checked here, on the two batches above, and that check failed
// now and then (BAJAAPP-93). It assumed those two batches were created in the
// same second. Usually they are: the two saves are milliseconds apart. But
// each save reads the clock once, and if the clock ticks over to the next
// second between them, the first batch is one second older. Newest-first then
// decides the order on its own, and the ids never come into it. The check
// predicted the order from the ids alone, and random ids agree with
// newest-first only half the time.

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

// --- batches sharing a timestamp: the id decides, compared byte by byte -----------------
//
// Last in the file on purpose. It creates four more batches, and the checks
// above count every batch this test's user has made, so they must run first.
//
// Some background, for anyone new to this.
//
// The listing shows the newest batch first. When two batches have the same
// creation time (criado_em only stores whole seconds), it needs a second rule
// to decide which comes first. Otherwise the database may pick either order,
// and might pick differently next time. The second rule is "larger batch id
// first" (ORDER BY lote_id DESC in Lotes::pagina).
//
// "Larger" depends on the column's COLLATION. A collation is the database's
// rule for comparing text. Some collations ignore case, so "a" and "A" count
// as the same letter. lote_id uses ascii_bin, which compares the raw byte of
// each character. Every capital letter has a smaller byte than every
// lowercase one ("A" is 65, "Z" is 90, "a" is 97), and "a" and "A" are two
// different letters.
//
// That is not a matter of taste. Batch ids are random base64url, which is
// case-sensitive, so "…aB…" and "…Ab…" are two different batches. Under a
// collation that ignored case, the database would treat them as ONE batch:
// it would merge them in this listing and delete both when asked to delete
// one.

// First, the column really is ascii_bin. If someone ever changes it, this
// says so plainly, instead of the ordering checks below failing for no
// obvious reason.
$colacao = Propel::getConnection()->query(
    "SELECT COLLATION_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes' AND COLUMN_NAME = 'lote_id'"
)->fetchColumn();
T::same('ascii_bin', $colacao, 'lote_id compares byte by byte (ascii_bin), so case matters');

// Four batches with ids chosen by hand. gravar() accepts an id from the
// caller, so nothing here is random and every run checks the same thing.
// Each id is 22 characters, the length of a real one. The first 12
// characters are a label we can search for, so only these batches are
// looked at.
//
//   Pair 1 differs ONLY in case. Compared byte by byte they are two ids, and
//   lowercase "a" (97) is larger than capital "A" (65), so the lowercase one
//   comes first. If case were ignored they would be one id, and the listing
//   would show a single batch.
$caixaBaixa = 'ZZLotesCaixaaaaaaaaaaa';
$caixaAlta  = 'ZZLotesCaixaAAAAAAAAAA';
//   Pair 2 is where the two ways of comparing give OPPOSITE answers. Byte by
//   byte, "a" (97) is larger than "B" (66), so the "a" id comes first.
//   Ignoring case, "B" comes after "a" in the alphabet, so the "B" id would
//   come first.
$ordemA = 'ZZLotesOrdemaaaaaaaaaa';
$ordemB = 'ZZLotesOrdemBBBBBBBBBB';

// One row per batch. Each person has a different document so the duplicate
// check does not pause any of them for review. Names start with $prefix so
// $cleanup() removes them along with everything else.
$criarCom = static function (string $id, string $nome, string $cpf) use ($validador, $gravador, $evA): void {
    $linhas = [['evento' => $evA, 'nome' => $nome, 'funcao' => 'juiz', 'documento' => synthetic_cpf($cpf)]];
    $gravador->gravar((new Revisao($validador->validar($linhas)))->prontas(), $id);
};
$criarCom($caixaBaixa, $prefix . ' Elisa Fonseca Testeson', '282828282');
$criarCom($caixaAlta, $prefix . ' Fabio Gomes Testeson', '292929292');
$criarCom($ordemA, $prefix . ' Gisele Harada Testeson', '313131313');
$criarCom($ordemB, $prefix . ' Heitor Ilha Testeson', '323232323');

// Give all four the SAME creation time. Saving them takes a few
// milliseconds, and the clock could tick over to the next second partway
// through. That would hand the decision back to newest-first, which is
// exactly how the old check came to fail now and then. With one shared time,
// the tiebreaker is the only thing left to decide the order.
ParticipanteQuery::create()
    ->filterByLoteId([$caixaBaixa, $caixaAlta, $ordemA, $ordemB], Criteria::IN)
    ->update(['CriadoEm' => '2026-01-01 12:00:00']);

// Searching by the shared label lists only that pair.
$listar = static fn (string $rotulo): array => array_column((new Lotes([], 0, $rotulo))->pagina(1), 'id');

T::same(
    [$caixaBaixa, $caixaAlta],
    $listar('ZZLotesCaixa'),
    'ids differing only in case are two batches, lowercase first'
);
T::same(
    [$ordemA, $ordemB],
    $listar('ZZLotesOrdem'),
    'batches sharing a timestamp fall back to their id, compared byte by byte'
);

// The same rule in PHP. rsort with SORT_STRING compares byte by byte, as
// ascii_bin does. Plain rsort, as the old check used, can also compare two
// strings as numbers when both look like numbers. Batch ids almost never do,
// but SORT_STRING says exactly what is meant.
//
// Largest first: the two "Ordem" ids beat the two "Caixa" ids at their eighth
// character ("O" is 79, "C" is 67). Within each pair, the rules above apply.
$esperado = [$ordemB, $caixaAlta, $ordemA, $caixaBaixa];
rsort($esperado, SORT_STRING);
T::same([$ordemA, $ordemB, $caixaBaixa, $caixaAlta], $esperado, 'PHP byte order agrees with the database');
T::same($esperado, $listar('ZZLotes'), 'and all four together come back in that order');

$cleanup();
