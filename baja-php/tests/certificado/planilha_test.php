<?php

use Baja\Certificado\Insercao\Mapeamento;
use Baja\Certificado\Insercao\Planilha;

T::group('planilha');

// --- the ordinary paste ---------------------------------------------------------

$tsv = "22BR\tJoão Silva Santos\tcompetidor\t52998224725\n"
     . "22BR\tMaria Souza Lima\tComissão Técnica\t01234567890\n";

$p = Planilha::analisar($tsv);
T::same(2, count($p->linhas), 'two rows parsed');
T::same(4, $p->largura(), 'four columns wide');
T::same(['22BR', 'João Silva Santos', 'competidor', '52998224725'], $p->linhas[0], 'the first row round-trips');
T::ok('nothing was truncated', !$p->truncada());

// Windows line endings, and a trailing newline, which every paste has.
$p = Planilha::analisar("22BR\tA B\tjuiz\t123\r\n22BR\tC D\tjuiz\t456\r\n");
T::same(2, count($p->linhas), 'CRLF and a trailing newline produce two rows, not three');

// Blank rows from selecting past the end of the data.
$p = Planilha::analisar("22BR\tA B\tjuiz\t123\n\n\t\t\t\n22BR\tC D\tjuiz\t456\n");
T::same(2, count($p->linhas), 'blank rows are dropped');

// A clipboard BOM, which becomes part of the first cell if it survives.
$p = Planilha::analisar("\u{FEFF}22BR\tA B\tjuiz\t123\n");
T::same('22BR', $p->linhas[0][0], 'a BOM does not end up inside the first cell');

T::ok('an empty paste is empty', Planilha::analisar('')->vazia());
T::ok('whitespace alone is empty', Planilha::analisar("\n\n  \n")->vazia());

// --- Excel's quoting ---------------------------------------------------------------
//
// A cell holding a tab, a newline or a quote comes wrapped in double quotes.
// Splitting on tabs and newlines instead turns one cell into several rows, and
// the damage looks like a participant whose name is half a document number.

$comTab = "22BR\t\"Silva\tSantos\"\tjuiz\t123\n";
$p = Planilha::analisar($comTab);
T::same(1, count($p->linhas), 'a quoted tab does not split the row');
T::same("Silva\tSantos", $p->linhas[0][1], 'and the cell keeps its tab');

$comQuebra = "22BR\t\"Silva\nSantos\"\tjuiz\t123\n";
$p = Planilha::analisar($comQuebra);
T::same(1, count($p->linhas), 'a quoted newline does not split the row');
T::same("Silva\nSantos", $p->linhas[0][1], 'and the cell keeps its line break');

$comAspas = "22BR\t\"Silva \"\"Junior\"\" Santos\"\tjuiz\t123\n";
$p = Planilha::analisar($comAspas);
T::same('Silva "Junior" Santos', $p->linhas[0][1], 'doubled quotes are one quote');

// The default escape character would eat this. Excel never uses backslash.
$comBarra = "22BR\tSilva\\\tjuiz\t123\n";
$p = Planilha::analisar($comBarra);
T::same(4, count($p->linhas[0]), 'a cell ending in a backslash does not swallow the next tab');
T::same('Silva\\', $p->linhas[0][1], 'and keeps its backslash');

// --- the cap ------------------------------------------------------------------------

$grande = str_repeat("22BR\tA B\tjuiz\t123\n", Planilha::MAX_LINHAS + 50);
$p = Planilha::analisar($grande);
T::same(Planilha::MAX_LINHAS, count($p->linhas), 'the paste is capped');
T::same(Planilha::MAX_LINHAS + 50, $p->total, 'and the real total is reported');
T::ok('the truncation is visible', $p->truncada());

$exato = str_repeat("22BR\tA B\tjuiz\t123\n", Planilha::MAX_LINHAS);
T::ok('a paste exactly at the cap is not truncated', !Planilha::analisar($exato)->truncada());

// --- mapping --------------------------------------------------------------------------

$padrao = Mapeamento::padrao(4);
T::same(['evento', 'nome', 'funcao', 'documento'], array_values($padrao->colunas()), 'the default order');
T::ok('the default mapping is complete', $padrao->valido());
T::same([], $padrao->faltando(), 'nothing is missing from it');

$linha = ['22BR', 'João Silva', 'competidor', '52998224725'];
T::same(
    ['evento' => '22BR', 'nome' => 'João Silva', 'funcao' => 'competidor', 'documento' => '52998224725'],
    $padrao->aplicar($linha),
    'the default mapping reads a row'
);

// Reordered: name first, then document, then event. No funcao column at all.
$reordenado = new Mapeamento([0 => 'nome', 1 => 'documento', 2 => 'evento'], ['funcao' => 'comite']);
T::ok('a reordered mapping with a page-level funcao is complete', $reordenado->valido());
T::same(
    ['evento' => '22BR', 'nome' => 'João Silva', 'funcao' => 'comite', 'documento' => '529'],
    $reordenado->aplicar(['João Silva', '529', '22BR']),
    'columns are read in the order given and the fixed value fills the gap'
);

// An ignored column in the middle.
$comIgnorada = new Mapeamento([0 => 'evento', 1 => '', 2 => 'nome', 3 => 'funcao', 4 => 'documento']);
T::same(
    ['evento' => '22BR', 'nome' => 'João Silva', 'funcao' => 'juiz', 'documento' => '529'],
    $comIgnorada->aplicar(['22BR', 'lixo', 'João Silva', 'juiz', '529']),
    'an ignored column is skipped'
);

// Incomplete and contradictory mappings are refused rather than guessed at.
$semNome = new Mapeamento([0 => 'evento', 1 => 'funcao', 2 => 'documento']);
T::same(['nome'], $semNome->faltando(), 'a missing field is named');
T::ok('and the mapping is not usable', !$semNome->valido());

$duasVezes = new Mapeamento([0 => 'nome', 1 => 'nome', 2 => 'evento', 3 => 'funcao', 4 => 'documento']);
T::same(['nome'], $duasVezes->duplicados(), 'a field mapped twice is named');
T::ok('and that mapping is not usable either', !$duasVezes->valido());

// `nome` and `documento` cannot be supplied for a whole sheet — they are the
// two things that differ per person.
$fixoInvalido = new Mapeamento([0 => 'evento', 1 => 'funcao'], ['nome' => 'Alguém', 'documento' => '1']);
T::same([], $fixoInvalido->fixos(), 'nome and documento cannot be fixed for the whole paste');
T::same(['nome', 'documento'], $fixoInvalido->faltando(), 'so they still count as missing');

// A mapped column wins over a page-level value, rather than the two disagreeing
// silently.
$ambos = new Mapeamento([0 => 'evento', 1 => 'nome', 2 => 'funcao', 3 => 'documento'], ['funcao' => 'comite']);
T::same('juiz', $ambos->aplicar(['22BR', 'A B', 'juiz', '1'])['funcao'], 'the column wins over the page-level value');

// --- ragged rows ----------------------------------------------------------------------
//
// Flagged, never padded. A short row usually means a cell held a tab, which
// shifts every field after it — padding produces a row that validates cleanly
// and is about the wrong person.

T::same(4, $padrao->colunasEsperadas(), 'four columns are expected');
T::ok('a short row is irregular', $padrao->ehIrregular(['22BR', 'A B', 'juiz']));
T::ok('a full row is not', !$padrao->ehIrregular(['22BR', 'A B', 'juiz', '1']));
T::ok('a long row is not short', !$padrao->ehIrregular(['22BR', 'A B', 'juiz', '1', 'extra']));

$curto = new Mapeamento([0 => 'nome', 3 => 'documento'], ['evento' => '22BR', 'funcao' => 'juiz']);
T::same(4, $curto->colunasEsperadas(), 'expectation follows the highest mapped column');
T::ok('a row that stops before it is irregular', $curto->ehIrregular(['A B', 'x', 'y']));
