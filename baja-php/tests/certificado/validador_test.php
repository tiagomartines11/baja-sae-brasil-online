<?php

use Baja\Certificado\Insercao\Linha;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Validador;
use Baja\Model\EventoQuery;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;

T::group('validador');

if (!test_db_available()) {
    T::skip('validation tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventos = EventoQuery::create()->orderByEventoId()->limit(2)->find();
if (count($eventos) < 2) {
    T::skip('validation tests', 'need at least two events');
    return;
}
$evA = $eventos[0]->getEventoId();
$evB = $eventos[1]->getEventoId();

$prefix = 'ZZFixtureValidador';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()
        ->filterByNome($prefix . '%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)
        ->delete();
};
$cleanup();

$gravar = static function (string $nome, ?string $cpf, ?string $estrangeiro, string $evento, string $funcao) use ($prefix): Participante {
    $p = new Participante();
    $p->setNome($prefix . ' ' . $nome);
    $p->setFuncao($funcao);
    $p->setCpf($cpf);
    $p->setDocumentoEstrangeiro($estrangeiro);
    $p->setEventoId($evento);
    $p->setCriadoPor(test_user_id());
    $p->save();

    return $p;
};

$validador = new Validador();

/** @return Linha */
$uma = static function (array $campos) use ($validador): Linha {
    return $validador->validar([$campos])[0];
};

$codigos = static function (Linha $linha): array {
    return array_map(fn(Problema $p) => $p->codigo, $linha->problemas());
};

$cpfLivre = synthetic_cpf('321654987');

// --- the shape of a clean row --------------------------------------------------

$ok = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same(Linha::OK, $ok->situacao(), 'a clean row is OK');
T::same([], $codigos($ok), 'and carries no problems');
T::same($cpfLivre, $ok->cpf, 'with the CPF resolved');
T::same('competidor', $ok->funcao, 'and the funcao resolved');
T::same($evA, $ok->eventoId, 'and the evento resolved');
T::ok('a clean row can be written', $ok->podeGravar());

// The event code is a primary key, so a lowercase paste is that event or none.
$minusculo = $uma(['evento' => strtolower($evA), 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same($evA, $minusculo->eventoId, 'a lowercase event code resolves');

// --- required fields ------------------------------------------------------------

$vazia = $uma(['evento' => '', 'nome' => '', 'funcao' => '', 'documento' => '']);
T::same(4, count($vazia->erros()), 'an empty row reports every missing field, not just the first');
T::same(Linha::ERRO, $vazia->situacao(), 'and is an error');
T::ok('an empty row cannot be written', !$vazia->podeGravar());

// --- funcao ---------------------------------------------------------------------

foreach (['Comissão Técnica', 'comissao tecnica', 'COMISSAO TECNICA', 'comite'] as $escrita) {
    $linha = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => $escrita, 'documento' => $cpfLivre]);
    T::same('comite', $linha->funcao, "\"$escrita\" resolves to comite");
}

$comissario = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'Comissário', 'documento' => $cpfLivre]);
T::same('comissario', $comissario->funcao, 'Comissário resolves to comissario');
T::notSame('comite', $comissario->funcao, 'and never to comite');

$inventada = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'Comiss', 'documento' => $cpfLivre]);
T::ok('an unrecognised funcao is an error', in_array(Problema::FUNCAO_DESCONHECIDA, $codigos($inventada), true));
T::same(null, $inventada->funcao, 'and produces no nearest-match guess');

foreach (['fiscal', 'Fiscal', 'engenheiro'] as $obsoleta) {
    $linha = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => $obsoleta, 'documento' => $cpfLivre]);
    T::ok("\"$obsoleta\" is accepted", $linha->funcao !== null);
    T::ok("\"$obsoleta\" raises a warning", in_array(Problema::FUNCAO_OBSOLETA, $codigos($linha), true));
    T::same(Linha::AVISO, $linha->situacao(), "\"$obsoleta\" is a warning, not an error");
    T::ok("\"$obsoleta\" blocks the commit until confirmed", !$linha->podeGravar());

    $linha->resolver(Problema::FUNCAO_OBSOLETA, Problema::CONFIRMAR);
    T::ok("\"$obsoleta\" commits once confirmed", $linha->podeGravar());
}

// --- documents -------------------------------------------------------------------

$sci = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => '1.23457E+10']);
T::ok('scientific notation is an error', in_array(Problema::NOTACAO_CIENTIFICA, $codigos($sci), true));
T::same(null, $sci->cpf, 'and produces no CPF');
T::same(null, $sci->documentoEstrangeiro, 'and no foreign document either');

$typo = substr($cpfLivre, 0, 10) . (substr($cpfLivre, 10, 1) === '9' ? '8' : '9');
$ambigua = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $typo]);
T::ok('a failing checksum is a warning', in_array(Problema::DOCUMENTO_AMBIGUO, $codigos($ambigua), true));
T::same(null, $ambigua->cpf, 'and nothing is auto-assigned to the CPF column');
T::same(null, $ambigua->documentoEstrangeiro, 'nor to the foreign one');
T::ok('an ambiguous document blocks the commit', !$ambigua->podeGravar());
$ambigua->resolver(Problema::DOCUMENTO_AMBIGUO, Problema::LER_COMO_ESTRANGEIRO);
T::ok('until the user says which it is', $ambigua->podeGravar());

// --- characters the table cannot hold ---------------------------------------------

$cirilico = $uma(['evento' => $evA, 'nome' => 'Иван Петров', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::ok('a non-latin1 name is an error', in_array(Problema::CARACTERES_INVALIDOS, $codigos($cirilico), true));
T::same(null, $cirilico->nome, 'and no truncated name is carried forward');
T::ok('the offending characters are named', str_contains($cirilico->erros()[0]->mensagem, 'U+0418'));

$curly = $uma(['evento' => $evA, 'nome' => "Sean O\u{2019}Brien", 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same([], $codigos($curly), 'a curly apostrophe is not an error — cp1252 holds it');

// --- a name nobody can search for --------------------------------------------------

$umNome = $uma(['evento' => $evA, 'nome' => 'Pele', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::ok('a one-part name warns', in_array(Problema::NOME_UNICO, $codigos($umNome), true));
T::same(Linha::AVISO, $umNome->situacao(), 'as a warning, not an error');

// --- duplicates within one paste ------------------------------------------------

$duasVezes = $validador->validar([
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre],
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre],
]);
T::same([], $codigos($duasVezes[0]), 'the first occurrence is clean');
T::ok('the second is flagged', in_array(Problema::DUPLICADO_NO_LOTE, $codigos($duasVezes[1]), true));

// The same person twice in one event under different roles is not a duplicate.
$doisPapeis = $validador->validar([
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre],
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'orientador', 'documento' => $cpfLivre],
]);
T::same([], $codigos($doisPapeis[1]), 'two roles at one event are two certificates, not a duplicate');

// Nor is the same person and role at two different events.
$doisEventos = $validador->validar([
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre],
    ['evento' => $evB, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre],
]);
T::same([], $codigos($doisEventos[1]), 'the same role at another event is not a duplicate');

// The Excel-mangled form of a CPF is the same person as the padded one.
$cpfZeros = synthetic_cpf('001234567');
$mesmaPessoa = $validador->validar([
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfZeros],
    ['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => ltrim($cpfZeros, '0')],
]);
T::ok(
    'a CPF with and without its leading zeros is one person within a paste',
    in_array(Problema::DUPLICADO_NO_LOTE, $codigos($mesmaPessoa[1]), true)
);

// --- duplicates against the database ---------------------------------------------

$cpfExistente = synthetic_cpf('445566778');
$gravar('Joana Pereira Antunes', $cpfExistente, null, $evA, 'competidor');

$dup = $uma(['evento' => $evA, 'nome' => $prefix . ' Joana Pereira Antunes', 'funcao' => 'competidor', 'documento' => $cpfExistente]);
T::ok('an existing row is a duplicate', in_array(Problema::DUPLICADO, $codigos($dup), true));
T::ok('and the row it duplicates is carried', $dup->duplicado !== null);
T::ok('a duplicate blocks the commit', !$dup->podeGravar());
$dup->resolver(Problema::DUPLICADO, Problema::IGNORAR);
T::ok('until skipped or updated', $dup->podeGravar());
T::ok('and skipping writes nothing', $dup->ehIgnorada());

$outraFuncao = $uma(['evento' => $evA, 'nome' => $prefix . ' Joana Pereira Antunes', 'funcao' => 'orientador', 'documento' => $cpfExistente]);
T::ok(
    'the same person at the same event in another role is not a duplicate',
    !in_array(Problema::DUPLICADO, $codigos($outraFuncao), true)
);

$outroEvento = $uma(['evento' => $evB, 'nome' => $prefix . ' Joana Pereira Antunes', 'funcao' => 'competidor', 'documento' => $cpfExistente]);
T::ok(
    'the same person and role at another event is not a duplicate',
    !in_array(Problema::DUPLICADO, $codigos($outroEvento), true)
);

// Pasting the same sheet twice: every row already exists.
$folha = [];
foreach (['competidor', 'orientador', 'juiz'] as $i => $funcao) {
    $gravar('Repetida ' . $funcao . ' Testeson', synthetic_cpf('11223344' . $i), null, $evA, $funcao);
}
foreach (ParticipanteQuery::create()->filterByNome($prefix . ' Repetida%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->find() as $row) {
    $folha[] = [
        'evento'    => $row->getEventoId(),
        'nome'      => $row->getNome(),
        'funcao'    => $row->getFuncao(),
        'documento' => $row->getCpf(),
    ];
}
T::same(3, count($folha), 'the sheet has three rows');
$recolada = $validador->validar($folha);
$todasDuplicadas = true;
foreach ($recolada as $linha) {
    if (!in_array(Problema::DUPLICADO, $codigos($linha), true)) {
        $todasDuplicadas = false;
    }
}
T::ok('pasting the same sheet twice flags every row as a duplicate', $todasDuplicadas);

// --- name conflicts ----------------------------------------------------------------

$cpfNome = synthetic_cpf('778899001');
$gravar('João Pedro Bresolin', $cpfNome, null, $evA, 'competidor');

// Accents only: the matcher recognises it, so it is the minor warning.
$acentos = $uma(['evento' => $evB, 'nome' => $prefix . ' Joao Pedro Bresolin', 'funcao' => 'competidor', 'documento' => $cpfNome]);
T::ok('an accent-only difference is a minor warning', in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($acentos), true));
T::ok('and not the harder one', !in_array(Problema::NOME_DIVERGENTE, $codigos($acentos), true));

// A different first name is not a spelling difference.
$outraPessoa = $uma(['evento' => $evB, 'nome' => $prefix . ' Maria Clara Bresolin', 'funcao' => 'competidor', 'documento' => $cpfNome]);
T::ok('a different first name is not minor', !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($outraPessoa), true));
T::ok('it warns harder', in_array(Problema::NOME_DIVERGENTE, $codigos($outraPessoa), true));

// An exact match says nothing at all.
$identico = $uma(['evento' => $evB, 'nome' => $prefix . ' João Pedro Bresolin', 'funcao' => 'competidor', 'documento' => $cpfNome]);
T::ok('an identical name is silent', !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($identico), true));
T::ok('and raises no harder warning either', !in_array(Problema::NOME_DIVERGENTE, $codigos($identico), true));

// All three resolutions are offered, both ways.
foreach ([$acentos, $outraPessoa] as $conflito) {
    foreach ($conflito->avisos() as $aviso) {
        if ($aviso->codigo === Problema::NOME_DIVERGENTE_LEVE || $aviso->codigo === Problema::NOME_DIVERGENTE) {
            T::same(
                [Problema::USAR_EXISTENTE, Problema::ATUALIZAR_NOME, Problema::MANTER_AMBOS],
                $aviso->resolucoes,
                'a name conflict offers three resolutions'
            );
        }
    }
}

// --- an unknown event ---------------------------------------------------------------

$semEvento = $uma(['evento' => '99ZZ', 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::ok('an unknown event is an error', in_array(Problema::EVENTO_DESCONHECIDO, $codigos($semEvento), true));

// --- resolutions are not free-form ----------------------------------------------------
//
// The review screen posts these back, so a value that is not one of the ones
// offered has to bounce off rather than be recorded.

$naoOferecida = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'fiscal', 'documento' => $cpfLivre]);
$naoOferecida->resolver(Problema::FUNCAO_OBSOLETA, Problema::IGNORAR);
T::same(null, $naoOferecida->resolucao(Problema::FUNCAO_OBSOLETA), 'a resolution that was not offered is refused');
T::ok('and the row still cannot be written', !$naoOferecida->podeGravar());

$cleanup();
