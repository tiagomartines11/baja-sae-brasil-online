<?php

use Baja\Certificado\Funcao;

T::group('funcao');

// --- the table itself --------------------------------------------------------

$todas = ['competidor', 'orientador', 'fiscal', 'comissario', 'juiz', 'comite', 'engenheiro', 'assessor'];
T::same($todas, array_keys(Funcao::labels()), 'every stored value is in the table');

T::same('Comissão Técnica', Funcao::label('comite'), 'comite is printed COMISSÃO TÉCNICA');
T::same('Assessor Técnico', Funcao::label('assessor'), 'assessor is printed Assessor Técnico');
T::same('Professor Orientador', Funcao::label('orientador'), 'orientador keeps the name issued certificates carry');
T::same('', Funcao::label('inexistente'), 'an unknown code renders nothing rather than guessing');

// --- deprecated values -------------------------------------------------------

T::ok('fiscal is deprecated', Funcao::isDeprecated('fiscal'));
T::ok('engenheiro is deprecated', Funcao::isDeprecated('engenheiro'));
T::ok('comissario is not deprecated', !Funcao::isDeprecated('comissario'));

$selecionaveis = array_keys(Funcao::selectable());
T::ok('fiscal is not offered for new records', !in_array('fiscal', $selecionaveis, true));
T::ok('engenheiro is not offered for new records', !in_array('engenheiro', $selecionaveis, true));
T::same(6, count($selecionaveis), 'six roles remain selectable');

// The point of keeping them: certificates already carrying them were validly
// issued and must keep rendering. `fiscal` used to render nothing at all.
T::same('Fiscal', Funcao::label('fiscal'), 'a deprecated role still renders its label');
T::same('Engenheiro', Funcao::label('engenheiro'), 'engenheiro still renders its label');
T::ok(
    'a fiscal certificate has a body sentence',
    str_contains(Funcao::texto('fiscal', 'EVENTO'), 'FISCAL')
);

// --- resolving a pasted value ------------------------------------------------

T::same('comite', Funcao::resolve('comite'), 'the stored code resolves to itself');
T::same('comite', Funcao::resolve('Comissão Técnica'), 'the display name resolves');
T::same('comite', Funcao::resolve('comissao tecnica'), 'unaccented and lowercase resolves');
T::same('comite', Funcao::resolve('COMISSÃO TÉCNICA'), 'uppercase resolves');
T::same('comite', Funcao::resolve('  Comissão   Técnica  '), 'stray whitespace resolves');

T::same('comissario', Funcao::resolve('Comissário'), 'Comissário resolves to comissario');
T::same('comissario', Funcao::resolve('comissario'), 'unaccented Comissário resolves');
T::notSame('comite', Funcao::resolve('Comissário'), 'Comissário never resolves to comite');
T::notSame('comite', Funcao::resolve('comissario'), 'the shared prefix does not reach comite');

T::same('assessor', Funcao::resolve('Assessor Técnico'), 'Assessor Técnico resolves');
T::same('orientador', Funcao::resolve('Orientador'), 'the bare code resolves whatever the display name is');
T::same('orientador', Funcao::resolve('Professor Orientador'), 'the display name resolves too');

// Unknown values are the user's to resolve, never the code's to guess at.
T::same(null, Funcao::resolve('Comiss'), 'a prefix is not a match');
T::same(null, Funcao::resolve('Comissão'), 'half a display name is not a match');
T::same(null, Funcao::resolve('Comissão Tecnica Junior'), 'a longer string is not a match');
T::same(null, Funcao::resolve('piloto'), 'an unrecognised role is not a match');
T::same(null, Funcao::resolve(''), 'an empty value is not a match');
T::same(null, Funcao::resolve('   '), 'whitespace alone is not a match');

// --- body sentences ----------------------------------------------------------

$cab = '<b>Baja SAE BRASIL</b>, Piracicaba, no período de <b>1 a 4 de março</b>';

T::same(
    'Participou da ' . $cab . '.',
    Funcao::texto('competidor', $cab),
    'a competitor with no carga horária'
);
T::same(
    'Participou da ' . $cab . ', com carga horária de 40 horas.',
    Funcao::texto('competidor', $cab, 40),
    'a competitor with carga horária'
);
T::same(
    'Participou da ' . $cab . ' na função de <b>PROFESSOR ORIENTADOR</b>.',
    Funcao::texto('orientador', $cab, 40),
    'an orientador participou, and carries no carga horária'
);
T::same(
    'Realizou trabalho voluntário na organização da ' . $cab . ' na função de <b>COMISSÃO TÉCNICA</b>.',
    Funcao::texto('comite', $cab),
    'comité realizou trabalho voluntário'
);
T::same(
    'Realizou trabalho voluntário na organização da ' . $cab . ' na função de <b>FISCAL</b>.',
    Funcao::texto('fiscal', $cab),
    'fiscal reads like every other voluntary role'
);
T::same('', Funcao::texto('inexistente', $cab), 'an unknown code produces no sentence');

// The label and the sentence must name the same thing, for every role. This is
// the invariant the two switch statements existed to keep and did not.
foreach (Funcao::labels() as $codigo => $label) {
    T::ok(
        "$codigo has both a label and a sentence",
        $label !== '' && Funcao::texto($codigo, $cab) !== ''
    );
}
