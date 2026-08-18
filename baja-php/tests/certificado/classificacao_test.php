<?php

use Baja\Certificado\Insercao\ClassificacaoDocumento as C;
use Baja\Certificado\Insercao\Texto;

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
T::same([C::CPF, C::ESTRANGEIRO], C::de($comTypo)->leiturasPossiveis(), 'and both readings are offered');
T::same($comTypo, C::de($comTypo)->cpf, 'the CPF reading is carried, not chosen');
T::same($comTypo, C::de($comTypo)->estrangeiro, 'and so is the foreign one');

T::same(C::AMBIGUO, C::de('123456789012')->tipo, 'twelve digits are ambiguous, not a CPF');
T::same(
    [C::ESTRANGEIRO],
    C::de('123456789012')->leiturasPossiveis(),
    'and only the foreign reading is possible for them'
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
