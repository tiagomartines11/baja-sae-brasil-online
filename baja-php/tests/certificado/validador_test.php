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

// A case-only difference is not a difference. The stored spelling and the
// pasted one both proper-case to the same string, so nothing about this row
// needs a person: whichever way it is answered, the same name is written.
// Asking anyway costs a decision on every row of a sheet whose author
// capitalised the particles, and buries the rows that really do need one.
$cpfParticula = synthetic_cpf('665544332');
$gravar('Breno José Marques de Britto', $cpfParticula, null, $evA, 'competidor');

$particula = $uma([
    'evento'    => $evB,
    'nome'      => $prefix . ' Breno José Marques De Britto',
    'funcao'    => 'competidor',
    'documento' => $cpfParticula,
]);
T::ok(
    'a capitalised particle raises no name warning',
    !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($particula), true)
        && !in_array(Problema::NOME_DIVERGENTE, $codigos($particula), true)
);

// The same, one word at a time rather than a whole sheet in capitals: this is
// the "Fulano de Tal" / "Fulano De Tal" pair the testers reported.
$mistura = $uma([
    'evento'    => $evB,
    'nome'      => mb_strtolower($prefix . ' Breno José Marques de Britto', 'UTF-8'),
    'funcao'    => 'competidor',
    'documento' => $cpfParticula,
]);
T::ok(
    'and neither does an all-lowercase spelling of it',
    !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($mistura), true)
        && !in_array(Problema::NOME_DIVERGENTE, $codigos($mistura), true)
);

// What the rule must not swallow. A difference the save pipeline would keep is
// still a difference, and still a question for the operator.
$semMeio = $uma([
    'evento'    => $evB,
    'nome'      => $prefix . ' Breno Marques de Britto',
    'funcao'    => 'competidor',
    'documento' => $cpfParticula,
]);
T::ok(
    'a dropped middle name still asks',
    in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($semMeio), true)
);

$outroPrimeiro = $uma([
    'evento'    => $evB,
    'nome'      => $prefix . ' Marcos Antonio Ferreira Souza',
    'funcao'    => 'competidor',
    'documento' => $cpfParticula,
]);
T::ok(
    'and so does a different first name',
    in_array(Problema::NOME_DIVERGENTE, $codigos($outroPrimeiro), true)
);

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

// --- events by either name, as well as by code -------------------------------------
//
// A sheet exported from this system carries codes. One built by hand carries
// one of the two names the event actually goes by: the formal `nome` a
// certificate prints, or the short `titulo` with the year in it. Which one
// depends on where the sheet came from, and neither is more correct.

$nomeDoEvento   = html_entity_decode((string) $eventos[0]->getNome(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tituloDoEvento = html_entity_decode((string) $eventos[0]->getTitulo(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

$porNome = $uma(['evento' => $nomeDoEvento, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same($evA, $porNome->eventoId, 'the full event name resolves to its code');
T::same([], $codigos($porNome), 'and raises nothing');

$semAcento = $uma(['evento' => \Baja\Certificado\Nome::chave($nomeDoEvento), 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same($evA, $semAcento->eventoId, 'unaccented and lowercase resolves too');

$comEspacos = $uma(['evento' => '  ' . mb_strtoupper($nomeDoEvento) . '  ', 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same($evA, $comEspacos->eventoId, 'so does uppercase with stray whitespace');

// The stored names carry HTML entities as literal text. A person pastes the
// name with a real space in it, so the undecoded form must not be what we
// compare against.
T::ok(
    'the stored name really does carry an entity, which is why decoding matters',
    str_contains((string) $eventos[0]->getNome(), '&nbsp;') || !str_contains($nomeDoEvento, '&')
);

$codigoAinda = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same($evA, $codigoAinda->eventoId, 'the code still works');

// The short title, which is a different string from the formal name and is
// what a sheet built off the site's own menus tends to carry.
if ($tituloDoEvento !== '') {
    T::notSame($nomeDoEvento, $tituloDoEvento, 'the two names really are different strings');

    $porTitulo = $uma(['evento' => $tituloDoEvento, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
    T::same($evA, $porTitulo->eventoId, 'the short title resolves to the same code');
    T::same([], $codigos($porTitulo), 'and raises nothing');

    $tituloFolded = $uma(['evento' => \Baja\Certificado\Nome::chave($tituloDoEvento), 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
    T::same($evA, $tituloFolded->eventoId, 'unaccented and lowercase, the title still resolves');

    // Both readings of the same event must not look like two candidates and
    // trip the ambiguity check against the event itself.
    T::same([], (new \Baja\Certificado\Insercao\Eventos())->ambiguos($tituloDoEvento), 'one event is never ambiguous with itself');
    T::same([], (new \Baja\Certificado\Insercao\Eventos())->ambiguos($nomeDoEvento), 'by either of its names');
}

// A title belonging to a different event resolves to that one, not this one —
// which is the whole reason the year in the title matters.
$outroTitulo = html_entity_decode((string) $eventos[1]->getTitulo(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
if ($outroTitulo !== '' && $outroTitulo !== $tituloDoEvento) {
    $porOutro = $uma(['evento' => $outroTitulo, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
    T::same($evB, $porOutro->eventoId, "another event's title resolves to that event");
    T::notSame($evA, $porOutro->eventoId, 'and never to this one');
}

$quase = $uma(['evento' => substr($nomeDoEvento, 0, 12), 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::ok('half an event name is an error, not a nearest match', in_array(Problema::EVENTO_DESCONHECIDO, $codigos($quase), true));

$meioTitulo = $uma(['evento' => 'Baja SAE BRASIL', 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::ok(
    'a title without its year is an error, not a guess at the latest event',
    in_array(Problema::EVENTO_DESCONHECIDO, $codigos($meioTitulo), true)
        || in_array(Problema::EVENTO_AMBIGUO, $codigos($meioTitulo), true)
);
T::same(null, $meioTitulo->eventoId, 'and resolves to nothing');
T::same(null, $quase->eventoId, 'and resolves to nothing');

// --- separate CPF and passport columns ----------------------------------------------

$passaporteDigitos = '00987654';

$semColuna = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $passaporteDigitos]);
T::ok('a digits-only passport with no column hint has to be asked about',
    in_array(Problema::DOCUMENTO_AMBIGUO, $codigos($semColuna), true));

$comColuna = $validador->validar([[
    'evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor',
    'documento' => $passaporteDigitos, 'documento_coluna' => 'estrangeiro',
]])[0];
T::same([], $codigos($comColuna), 'the passport column answers it with no warning at all');
T::same($passaporteDigitos, $comColuna->documentoEstrangeiro, 'and files it verbatim');
T::same(null, $comColuna->cpf, 'never in the CPF column');
T::ok('so the row is ready to write', $comColuna->podeGravar());

$cpfNaColuna = $validador->validar([[
    'evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor',
    'documento' => ltrim(synthetic_cpf('001234567'), '0'), 'documento_coluna' => 'cpf',
]])[0];
T::same(synthetic_cpf('001234567'), $cpfNaColuna->cpf, 'a CPF column still pads the leading zeros back');
T::same([], $codigos($cpfNaColuna), 'and raises nothing');

$letrasNoCpf = $validador->validar([[
    'evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor',
    'documento' => 'AB123456', 'documento_coluna' => 'cpf',
]])[0];
T::ok('letters in the CPF column are an error', in_array(Problema::DOCUMENTO_CONTRADIZ, $codigos($letrasNoCpf), true));
T::same(null, $letrasNoCpf->documentoEstrangeiro, 'and are not quietly refiled as a passport');

$ambos = $validador->validar([[
    'evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor',
    'documento' => $cpfLivre, 'documento_coluna' => 'ambos',
]])[0];
T::ok('CPF and passport both filled is an error', in_array(Problema::DOIS_DOCUMENTOS, $codigos($ambos), true));

// A typo'd CPF in a CPF column is still worth asking about: the column saying
// "CPF" does not make the digits right.
$typoNaColuna = $validador->validar([[
    'evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor',
    'documento' => $typo, 'documento_coluna' => 'cpf',
]])[0];
T::ok('a failing checksum in a CPF column still asks', in_array(Problema::DOCUMENTO_AMBIGUO, $codigos($typoNaColuna), true));

// --- name casing is a rule, not a question -------------------------------------------
//
// The stored name is what the certificate prints, and sheets arrive ALL CAPS
// more often than not. That outcome is certain; what the rule costs is
// occasional and small, and every adjustment is shown on the review screen.

$gritando = $uma(['evento' => $evA, 'nome' => 'ANA PAULA FERREIRA LIMA', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same('Ana Paula Ferreira Lima', $gritando->nome, 'an ALL CAPS name is recased without being asked about');
T::ok('and it is recorded as adjusted', $gritando->caixaAjustada);
T::same([], $codigos($gritando), 'raising no problem of any kind');
T::same(Linha::OK, $gritando->situacao(), 'the row is simply OK');
T::ok('and needs no answer before it can be written', $gritando->podeGravar());

$baixa = $uma(['evento' => $evA, 'nome' => 'joao da silva santos', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same('Joao da Silva Santos', $baixa->nome, 'all-lowercase is recased too, connectives intact');
T::ok('and is also marked adjusted', $baixa->caixaAjustada);

// The paste is kept, so the review can show what changed.
T::same('ANA PAULA FERREIRA LIMA', $gritando->nomeBruto, 'the pasted name is still available to show');

// Anything mixed is somebody's own spelling and is left alone.
$normal = $uma(['evento' => $evA, 'nome' => 'Ana Paula Ferreira Lima', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same('Ana Paula Ferreira Lima', $normal->nome, 'an ordinary name is untouched');
T::ok('and is not marked adjusted', !$normal->caixaAjustada);

$deliberado = $uma(['evento' => $evA, 'nome' => 'Ana Paula MACHADO Lima', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same('Ana Paula MACHADO Lima', $deliberado->nome, 'a deliberately capitalised surname survives');
T::ok('and counts as nothing to report', !$deliberado->caixaAjustada);

// Accents are not invented, which is the rule's known cost.
$semAcentos = $uma(['evento' => $evA, 'nome' => 'JOAO GONCALVES SOUZA', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same('Joao Goncalves Souza', $semAcentos->nome, 'a name that lost its accents does not get them back');

// The recasing happens before the name is compared to what is on file, which
// removes a class of warnings that were never worth raising.
// The fixture prefix is stored in the case the rule produces, so that the
// only thing this check is comparing is the participant's own name. The
// cleanup still finds it: LIKE on this column is case-insensitive.
$cpfCaixa   = synthetic_cpf('909876543');
$nomeCaixa  = \Baja\Certificado\Insercao\Texto::caixaDeNome($prefix . ' Marcelo Antunes Prado');
$p = new Participante();
$p->setNome($nomeCaixa);
$p->setFuncao('competidor');
$p->setCpf($cpfCaixa);
$p->setEventoId($evA);
$p->setCriadoPor(test_user_id());
$p->save();

$mesmoNome = $uma(['evento' => $evB, 'nome' => mb_strtoupper($nomeCaixa, 'UTF-8'), 'funcao' => 'competidor', 'documento' => $cpfCaixa]);
T::same($nomeCaixa, $mesmoNome->nome, 'the ALL CAPS paste recases back to the stored spelling');
T::ok(
    'an ALL CAPS spelling of a stored name is not a name conflict',
    !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($mesmoNome), true)
        && !in_array(Problema::NOME_DIVERGENTE, $codigos($mesmoNome), true)
);

$cleanup();

// --- an invalid CPF cannot be forced into the CPF column ---------------------------
//
// The check digits exist to catch transcription errors, so digits that fail
// them are a typo with near certainty. Recording one as a CPF would create a
// person nobody can find by their real number — including themselves — so the
// option is not offered. What is offered is the reading that is actually
// possible: a passport kept as digits.

$typoLivre = substr($cpfLivre, 0, 10) . (substr($cpfLivre, 10, 1) === '9' ? '8' : '9');
T::ok('the fixture really is an invalid CPF', !\Baja\Certificado\Documento::isValidCpf($typoLivre));

$ruim = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $typoLivre]);

$avisoDoc = null;
foreach ($ruim->avisos() as $p) {
    if ($p->codigo === Problema::DOCUMENTO_AMBIGUO) { $avisoDoc = $p; }
}
T::ok('a failing checksum still raises the document warning', $avisoDoc !== null);
T::same([Problema::LER_COMO_ESTRANGEIRO], $avisoDoc->resolucoes, 'with exactly one resolution offered');
T::ok('and it is not "it is a CPF"', !in_array('cpf', $avisoDoc->resolucoes, true));
T::ok('the message says it cannot be recorded as a CPF', str_contains($avisoDoc->mensagem, 'não pode ser gravado como CPF'));

// Confirming sends it to the foreign column, and never to cpf.
$confirmado = $validador->validar(
    [['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $typoLivre]],
    [1 => [Problema::DOCUMENTO_AMBIGUO => Problema::LER_COMO_ESTRANGEIRO]]
)[0];
T::same(null, $confirmado->cpf, 'confirming never fills the CPF column');
T::same($typoLivre, $confirmado->documentoEstrangeiro, 'it fills the foreign one');
T::ok('and the row can then be written', $confirmado->podeGravar());

// A resolution the form no longer offers is refused even if posted by hand.
$forcado = $validador->validar(
    [['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $typoLivre]],
    [1 => [Problema::DOCUMENTO_AMBIGUO => 'cpf']]
)[0];
T::same(null, $forcado->resolucao(Problema::DOCUMENTO_AMBIGUO), 'posting the old value by hand is refused');
T::same(null, $forcado->cpf, 'and writes nothing to the CPF column');
T::ok('so the row still cannot be written', !$forcado->podeGravar());

// The same holds when the operator mapped a column as CPF: their say-so about
// the column does not make the digits right.
$naColunaCpf = $validador->validar([[
    'evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor',
    'documento' => $typoLivre, 'documento_coluna' => 'cpf',
]])[0];
$avisoColuna = null;
foreach ($naColunaCpf->avisos() as $p) {
    if ($p->codigo === Problema::DOCUMENTO_AMBIGUO) { $avisoColuna = $p; }
}
T::same([Problema::LER_COMO_ESTRANGEIRO], $avisoColuna->resolucoes, 'a CPF column gets the same single resolution');
T::ok('and is told to fix the sheet', str_contains($avisoColuna->mensagem, 'corrija na planilha'));

// A valid CPF is unaffected: no warning, straight into the CPF column.
$bom = $uma(['evento' => $evA, 'nome' => 'Fulano de Tal Testeson', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same($cpfLivre, $bom->cpf, 'a valid CPF still goes to the CPF column');
T::same([], $codigos($bom), 'with nothing to answer');

$cleanup();

// --- whitespace, at the ends and inside --------------------------------------------
//
// The ends are trimmed when the row is built, and that covers the invisible
// ones a paste actually carries. The inside is collapsed here, because a
// double space survives into the certificate and prints there — and because
// the stored name is compared with === against what is on file, so "Ana  Paula"
// and "Ana Paula" would otherwise read as two different people.

$espacos = [
    'espaços comuns nas pontas'   => ['  Joao Pedro Silva  ',                'Joao Pedro Silva'],
    'tabulação e quebra de linha' => ["\tJoao Pedro Silva\n",                'Joao Pedro Silva'],
    'espaço não separável'        => ["\u{00A0}Joao Pedro Silva\u{00A0}",    'Joao Pedro Silva'],
    'BOM de área de transferência'=> ["\u{FEFF}Joao Pedro Silva",            'Joao Pedro Silva'],
    'espaço de largura zero'      => ["\u{200B}Joao Pedro Silva\u{200B}",    'Joao Pedro Silva'],
    'espaço duplo interno'        => ['Kauan  Rocha Mendes',                 'Kauan Rocha Mendes'],
    'vários espaços internos'     => ['Joao   Pedro    Silva',               'Joao Pedro Silva'],
    'tabulação interna'           => ["Joao\tPedro Silva",                   'Joao Pedro Silva'],
    'quebra de linha interna'     => ["Ana\nPaula Lima",                     'Ana Paula Lima'],
    'mistura de invisíveis'       => ["Joao \u{00A0} Pedro",                 'Joao Pedro'],
];

foreach ($espacos as $rotulo => [$entrada, $esperado]) {
    $l = $uma(['evento' => $evA, 'nome' => $entrada, 'funcao' => 'competidor', 'documento' => $cpfLivre]);
    T::same($esperado, $l->nome, $rotulo);
}

// A name that needed no cleaning is untouched, and single spaces survive.
$intacto = $uma(['evento' => $evA, 'nome' => 'Ana Paula Ferreira Lima', 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::same('Ana Paula Ferreira Lima', $intacto->nome, 'an already-clean name is unchanged');

// Whitespace alone is still an absent name, not a name made of one space.
$soEspacos = $uma(['evento' => $evA, 'nome' => "  \u{00A0}\t ", 'funcao' => 'competidor', 'documento' => $cpfLivre]);
T::ok('whitespace alone is a missing name', in_array(Problema::CAMPO_OBRIGATORIO, $codigos($soEspacos), true));
T::same(null, $soEspacos->nome, 'and carries no name forward');

// The collapse happens before the name is compared to what is on file, so a
// double space is not a name conflict with the single-spaced row.
$cpfEspaco = synthetic_cpf('818273645');
$gravar('Renata Alves Prado', $cpfEspaco, null, $evA, 'competidor');
$comEspacoDuplo = $uma(['evento' => $evB, 'nome' => $prefix . '  Renata  Alves Prado', 'funcao' => 'competidor', 'documento' => $cpfEspaco]);
T::same($prefix . ' Renata Alves Prado', $comEspacoDuplo->nome, 'the pasted double spaces collapse');
T::ok(
    'so it is not a name conflict with the stored row',
    !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos($comEspacoDuplo), true)
        && !in_array(Problema::NOME_DIVERGENTE, $codigos($comEspacoDuplo), true)
);

$cleanup();
