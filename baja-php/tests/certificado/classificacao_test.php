<?php

use Baja\Certificado\Insercao\ClassificacaoDocumento as C;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Nome;

T::group('classificacao');

// --- CPF ---------------------------------------------------------------------

$cpf = synthetic_cpf('529982247');
T::same(C::CPF, C::de($cpf)->tipo, 'eleven valid digits are a CPF');
T::same($cpf, C::de($cpf)->cpf, 'and the stored form is the digits');
T::same(C::CPF, C::de('529.982.247-25')->tipo, 'punctuation does not change the answer');
T::same($cpf, C::de(' 529.982.247-25 ')->cpf, 'and the stored form is still eleven digits');

// The case this ordering exists for. Excel strips leading zeros from anything
// it read as a number, so a CPF beginning 00 arrives with nine digits — and a
// rule that took length as the discriminator would file it as foreign.
$comZeros = synthetic_cpf('001234567');
T::same('00' . substr($comZeros, 2), $comZeros, 'the fixture CPF really does begin with zeros');
T::same(C::CPF, C::de(ltrim($comZeros, '0'))->tipo, 'a CPF missing its leading zeros is still a CPF');
T::same($comZeros, C::de(ltrim($comZeros, '0'))->cpf, 'and the zeros are restored');
T::same(9, strlen(ltrim($comZeros, '0')), 'the input really was nine digits');

// --- foreign -----------------------------------------------------------------

T::same(C::ESTRANGEIRO, C::de('AB123456')->tipo, 'a letter makes it a foreign document');
T::same('AB123456', C::de('AB123456')->estrangeiro, 'stored verbatim');
T::same(null, C::de('AB123456')->cpf, 'and never as a CPF');
T::same(C::ESTRANGEIRO, C::de('X 12-345')->tipo, 'punctuation and spaces do not matter');
T::same('X 12-345', C::de('X 12-345')->estrangeiro, 'and it is still stored exactly as given');

// A passport whose digits happen to satisfy the CPF check digits is still a
// passport: the letter decides, and it decides first.
T::same(C::ESTRANGEIRO, C::de('AB' . $cpf)->tipo, 'a letter beats the check digits');

// --- ambiguous ---------------------------------------------------------------

$comTypo = substr($cpf, 0, 10) . (substr($cpf, 10, 1) === '9' ? '8' : '9');
T::same(C::AMBIGUO, C::de($comTypo)->tipo, 'digits failing the checksum are ambiguous');
T::ok('an ambiguous value is not resolved', !C::de($comTypo)->ehResolvida());
// One reading, and only one. There is no "record it as a CPF anyway": digits
// failing the check are a transcription error with near certainty, and writing
// them to `cpf` would create a person nobody can find by their real number.
// The two things the value can actually be are still both reachable — a
// passport kept as digits, which this confirms, or a typo, which is fixed in
// the sheet.
T::same([C::ESTRANGEIRO], C::de($comTypo)->leiturasPossiveis(), 'the only reading offered is the foreign one');
T::ok('never the CPF one', !in_array(C::CPF, C::de($comTypo)->leiturasPossiveis(), true));

// The CPF candidate is still carried on the classification — the padding is
// what tells the message how many digits it had — but carrying it is not
// offering it.
T::same($comTypo, C::de($comTypo)->cpf, 'the padded CPF candidate is still carried');
T::same($comTypo, C::de($comTypo)->estrangeiro, 'and so is the foreign one');

T::same(C::AMBIGUO, C::de('123456789012')->tipo, 'twelve digits are ambiguous, not a CPF');
T::same(
    [C::ESTRANGEIRO],
    C::de('123456789012')->leiturasPossiveis(),
    'and the foreign reading is the only one there too'
);
T::same(null, C::de('123456789012')->cpf, 'twelve digits carry no CPF reading');

T::same(C::AMBIGUO, C::de('11111111111')->tipo, 'a repeated-digit CPF is not auto-assigned');

// --- scientific notation ------------------------------------------------------
//
// The digits are genuinely gone. Producing any value from these would invent
// somebody's document number.

foreach (['1.23457E+10', '1,23457E+10', '1.23457E10', '5.29982E+10', '1E+11', '-1.5e-3'] as $sci) {
    T::same(C::NOTACAO_CIENTIFICA, C::de($sci)->tipo, "\"$sci\" is scientific notation");
    T::same(null, C::de($sci)->cpf, "\"$sci\" produces no CPF");
    T::same(null, C::de($sci)->estrangeiro, "\"$sci\" produces no foreign document");
}

// Things that merely look similar must survive.
T::same(C::ESTRANGEIRO, C::de('E123456')->tipo, 'a passport beginning with E is not notation');
T::same(C::ESTRANGEIRO, C::de('123456E')->tipo, 'a trailing letter is not notation');
T::same(C::ESTRANGEIRO, C::de('AB1E5')->tipo, 'an E inside letters is not notation');
T::same(C::CPF, C::de($cpf)->tipo, 'a plain CPF is not notation');

// --- nothing at all -----------------------------------------------------------

T::same(C::VAZIO, C::de('')->tipo, 'an empty document is empty');
T::same(C::VAZIO, C::de('   ')->tipo, 'whitespace alone is empty');
T::same(C::VAZIO, C::de('---')->tipo, 'punctuation alone carries no document');
T::same(C::VAZIO, C::de("\u{00A0}")->tipo, 'a non-breaking space alone is empty');

// --- text on the way to a latin1 column ---------------------------------------

T::same('João Silva', Texto::limpar('  João Silva  '), 'ordinary whitespace is trimmed');
T::same('João Silva', Texto::limpar("\u{00A0}João Silva\u{00A0}"), 'a non-breaking space is trimmed');
T::same('João Silva', Texto::limpar("\u{FEFF}João Silva"), 'a clipboard BOM is trimmed');
T::same('João  Silva', Texto::limpar('  João  Silva  '), 'inner spacing is left alone');

T::same([], Texto::naoArmazenaveis('João Gonçalves Ação'), 'accented Portuguese stores fine');
T::same([], Texto::naoArmazenaveis("O\u{2019}Brien"), 'a curly apostrophe stores fine — latin1 here is cp1252');
T::same([], Texto::naoArmazenaveis('Preço € 10'), 'so does the euro sign');
T::same([], Texto::naoArmazenaveis('Åsa Ødegård'), 'so do Nordic letters');

T::same(['И', 'в', 'а', 'н'], Texto::naoArmazenaveis('Иван'), 'Cyrillic is named character by character');
T::same(["\u{2011}"], Texto::naoArmazenaveis("Jean\u{2011}Luc"), 'a non-breaking hyphen is named');
T::ok('CJK is refused', Texto::naoArmazenaveis('田中') !== []);
T::same(
    ["\u{2011}"],
    Texto::naoArmazenaveis("Jean\u{2011}Luc\u{2011}Picard"),
    'a repeated offender is named once'
);

T::same('"‑" (U+2011)', Texto::descrever("\u{2011}"), 'an invisible character is described by code point');

// --- what an explicitly mapped column changes -------------------------------------
//
// A sheet with separate CPF and Passaporte columns states something the value
// alone cannot. This is the case that motivated it: a passport recorded as
// digits only, which is how this system recorded every one of them for years.

$passaporteSoNumeros = '00987654';
T::same(C::AMBIGUO, C::de($passaporteSoNumeros)->tipo, 'digits alone are ambiguous with no column to go on');
T::same(
    C::ESTRANGEIRO,
    C::de($passaporteSoNumeros, C::COLUNA_ESTRANGEIRA)->tipo,
    'but the passport column settles it'
);
T::same(
    $passaporteSoNumeros,
    C::de($passaporteSoNumeros, C::COLUNA_ESTRANGEIRA)->estrangeiro,
    'and it is stored verbatim, leading zeros and all'
);
T::same(null, C::de($passaporteSoNumeros, C::COLUNA_ESTRANGEIRA)->cpf, 'never as a CPF');

// A passport column is taken at its word even when the digits happen to pass
// the CPF check — which they sometimes do by coincidence, and which is exactly
// the misfiling that made this system store passports in a numeric column.
T::same(C::ESTRANGEIRO, C::de($cpf, C::COLUNA_ESTRANGEIRA)->tipo, 'the passport column beats the check digits');

// The CPF column, the other way.
T::same(C::CPF, C::de($cpf, C::COLUNA_CPF)->tipo, 'a valid CPF in the CPF column is a CPF');
T::same(C::CPF, C::de(ltrim($comZeros, '0'), C::COLUNA_CPF)->tipo, 'padding still happens there');

// A failing checksum in a CPF column still asks. A mistyped CPF is worth
// seeing, and the column saying "CPF" does not make the digits right.
T::same(C::AMBIGUO, C::de($comTypo, C::COLUNA_CPF)->tipo, 'a failing checksum still asks, even in a CPF column');
T::same(C::COLUNA_CPF, C::de($comTypo, C::COLUNA_CPF)->coluna, 'and remembers what the column claimed');

// Letters in a CPF column are two statements that disagree, and neither is
// ours to overrule.
T::same(C::CONTRADIZ_COLUNA, C::de('AB123456', C::COLUNA_CPF)->tipo, 'letters in a CPF column contradict it');
T::same(C::ESTRANGEIRO, C::de('AB123456')->tipo, 'the same value with no column is simply foreign');

// Scientific notation is refused whichever column it was pasted into: the
// digits are gone either way.
foreach ([C::COLUNA_QUALQUER, C::COLUNA_CPF, C::COLUNA_ESTRANGEIRA] as $coluna) {
    T::same(
        C::NOTACAO_CIENTIFICA,
        C::de('1.23457E+10', $coluna)->tipo,
        'scientific notation is refused in any column'
    );
}

// Both columns filled is one person with two documents.
T::same(C::DOIS_DOCUMENTOS, C::de('52998224725', C::COLUNA_AMBAS)->tipo, 'both columns filled is an error');

// An empty cell is still an absent document, whatever the column said.
T::same(C::VAZIO, C::de('', C::COLUNA_ESTRANGEIRA)->tipo, 'an empty passport cell carries no document');

// --- name casing --------------------------------------------------------------------

T::same('alta',  Texto::caixaUniforme('ANA PAULA FERREIRA'), 'ALL CAPS is detected');
T::same('baixa', Texto::caixaUniforme('ana paula ferreira'), 'all lowercase is detected');
T::same(null,    Texto::caixaUniforme('Ana Paula Ferreira'), 'ordinary casing is left alone');
T::same(null,    Texto::caixaUniforme('ANA paula'), 'and so is anything mixed — that is a mistake to look at, not to guess');
T::same(null,    Texto::caixaUniforme('12345'), 'a value with no letters has no case');

T::same('Ana Paula Ferreira Lima', Texto::caixaDeNome('ANA PAULA FERREIRA LIMA'), 'ALL CAPS gets a suggestion');
T::same('Joao da Silva Santos', Texto::caixaDeNome('joao da silva santos'), 'Portuguese connectives stay lowercase');
T::same('De Souza Lima', Texto::caixaDeNome('DE SOUZA LIMA'), 'except as the first word');
T::same("D'Angelo Pereira", Texto::caixaDeNome("d'angelo pereira"), 'a letter after an apostrophe is capitalised');
T::same('Jean-Luc Picard', Texto::caixaDeNome('JEAN-LUC PICARD'), 'and after a hyphen');
T::same('Jose de Souza Neto III', Texto::caixaDeNome('JOSE DE SOUZA NETO III'), 'a roman numeral is not turned into Iii');
T::same('João Pedro Silva', Texto::caixaDeNome('João Pedro Silva'), 'an already-correct name is unchanged');

// Accents are not restored. An ALL CAPS sheet has usually lost them, and
// putting them back would be inventing the spelling of somebody's name rather
// than correcting its case — which is the whole reason this is a suggestion.
T::same('Joao Goncalves', Texto::caixaDeNome('JOAO GONCALVES'), 'missing accents are not invented');
T::same('João Gonçalves', Texto::caixaDeNome('JOÃO GONÇALVES'), 'but present ones survive');

// The particle list is Nome::CONNECTIVES, not a copy of it. Brazilian
// orthography puts these in lowercase inside a name, and the certificate is a
// formal document over the president's signature: "Marques de Britto" is what
// a Brazilian reader expects to see, and "Marques De Britto" reads as wrong.
T::same(
    'Breno José Erasmi Marques de Britto',
    Texto::caixaDeNome('Breno José Erasmi Marques De Britto'),
    'a capitalised particle is put back in lowercase'
);
foreach (Nome::CONNECTIVES as $conectivo) {
    T::same(
        'Silva ' . $conectivo . ' Souza',
        Texto::caixaDeNome('SILVA ' . mb_strtoupper($conectivo, 'UTF-8') . ' SOUZA'),
        sprintf('"%s" is one of the words that stays lowercase', $conectivo)
    );
}

// The edge cases the review screen actually meets.
T::same(
    'João Guilherme Bresolin',
    Texto::caixaDeNome('JOÃO GUILHERME BRESOLIN'),
    'an ALL CAPS name with accents proper-cases without losing them'
);
T::same(
    "D'Ângelo Silva",
    Texto::caixaDeNome("D'ÂNGELO SILVA"),
    'the accented letter after an apostrophe is capitalised, not left as d\'ângelo'
);
T::same(
    'J. P. Silva',
    Texto::caixaDeNome('J. P. Silva'),
    'single-letter initials keep their capital and their full stop'
);
T::same(
    'Ana-Maria da Costa-Lima',
    Texto::caixaDeNome('ANA-MARIA DA COSTA-LIMA'),
    'both halves of a hyphenated surname are capitalised'
);
T::same(
    'De Souza da Silva',
    Texto::caixaDeNome('DE SOUZA DA SILVA'),
    'a particle in first position is capitalised — a name does not begin lowercase'
);

// --- the same name, differently cased -----------------------------------------------
//
// What the review screen asks about. A discrepancy the save pipeline would
// erase is not a discrepancy: surfacing it costs the operator a decision on
// something already decided, and buries the rows that need one.

T::ok(
    'a capitalised particle is not a difference',
    Texto::mesmoNome('Fulano de Tal', 'Fulano De Tal')
);
T::ok(
    'and neither is an ALL CAPS spelling of the same name',
    Texto::mesmoNome('FULANO DE TAL', 'Fulano de Tal')
);
T::ok(
    'nor an all-lowercase one',
    Texto::mesmoNome('Ana Paula Ferreira', 'ana paula ferreira')
);
T::ok(
    'an identical name is trivially the same name',
    Texto::mesmoNome('José da Silva', 'José da Silva')
);

// Narrow on purpose. Everything the save pipeline would *not* erase stays a
// difference, because the operator is the only one who knows which spelling
// the person uses.
T::ok(
    'an accent is still a difference — saving one does not turn it into the other',
    !Texto::mesmoNome('Joao Pedro Bresolin', 'João Pedro Bresolin')
);
T::ok(
    'a dropped middle name is still a difference',
    !Texto::mesmoNome('Ana Paula Ferreira', 'Ana Ferreira')
);
T::ok(
    'and so is a different first name',
    !Texto::mesmoNome('Ana Paula Ferreira', 'Maria Paula Ferreira')
);
