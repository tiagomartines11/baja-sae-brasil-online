<?php

use Baja\Certificado\Busca;
use Baja\Certificado\Certificado;
use Baja\Certificado\Insercao\Anulacao;
use Baja\Certificado\Insercao\Consulta;
use Baja\Certificado\Insercao\Problema;
use Baja\Certificado\Insercao\Validador;
use Baja\Certificado\Token;
use Baja\Model\EventoQuery;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('anulacao');

if (!test_db_available()) {
    T::skip('voiding tests', 'BAJA_TEST_DB is not 1');
    return;
}

$eventos = EventoQuery::create()->orderByEventoId()->limit(2)->find();
if (count($eventos) < 2) {
    T::skip('voiding tests', 'need at least two events');
    return;
}
$evA = $eventos[0]->getEventoId();
$evB = $eventos[1]->getEventoId();

$prefix  = 'ZZFixtureAnulacao';
$cleanup = static function () use ($prefix): void {
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)->delete();
};
$cleanup();

$gravar = static function (string $nome, string $cpf, string $ev, string $funcao) use ($prefix): Participante {
    $p = new Participante();
    $p->setNome($prefix . ' ' . $nome);
    $p->setCpf($cpf);
    $p->setEventoId($ev);
    $p->setFuncao($funcao);
    $p->setCriadoPor(test_user_id());
    $p->save();

    return $p;
};

$anulacao = new Anulacao(test_user_id());

// --- the reason is required, and is checked before anything is written ------------

T::ok('an empty reason is refused', Anulacao::problemasDoMotivo('') !== []);
T::ok('whitespace alone too', Anulacao::problemasDoMotivo('   ') !== []);
T::same([], Anulacao::problemasDoMotivo('emitido em duplicidade'), 'an ordinary reason is fine');
T::ok('a reason over the column length is refused',
    Anulacao::problemasDoMotivo(str_repeat('a', Anulacao::MOTIVO_MAX + 1)) !== []);
T::same([], Anulacao::problemasDoMotivo(str_repeat('a', Anulacao::MOTIVO_MAX)), 'exactly the limit is fine');

// Same latin1 check the insertion pages run: MySQL answers an unmappable
// character by refusing the statement, which inside this transaction would take
// every other row with it.
$ruim = Anulacao::problemasDoMotivo('Иван pediu');
T::ok('a reason with non-latin1 characters is refused', $ruim !== []);
T::ok('and the characters are named', str_contains($ruim[0], 'U+0418'));

$linha = $gravar('Ana Anulada Testeson', synthetic_cpf('919191919'), $evA, 'competidor');
$token = (string) $linha->getToken();

$recusou = false;
try {
    $anulacao->anular([$token], '');
} catch (\LogicException $e) {
    $recusou = true;
}
T::ok('voiding without a reason throws', $recusou);
ParticipanteQuery::create()->clear();
T::same(null, ParticipanteQuery::create()->filterByToken($token)->findOne()->getAnuladoEm(), 'and writes nothing');

// --- voiding ---------------------------------------------------------------------

T::ok('the certificate resolves before being voided', Certificado::fromToken($token) !== null);

T::same(1, $anulacao->anular([$token], 'emitido em duplicidade'), 'one certificate voided');

\Baja\Model\Map\ParticipanteTableMap::clearInstancePool();
$recarregada = ParticipanteQuery::create()->filterByToken($token)->findOne();
T::ok('the row is still there', $recarregada !== null);
T::ok('with a void timestamp', $recarregada->getAnuladoEm() instanceof \DateTimeInterface);
T::same(test_user_id(), (int) $recarregada->getAnuladoPor(), 'and who voided it');
T::same('emitido em duplicidade', (string) $recarregada->getAnuladoMotivo(), 'and why');
T::same(test_user_id(), (int) $recarregada->getCriadoPor(), 'the creation record is untouched');

// --- what a voided certificate does in public ---------------------------------------

T::same(null, Certificado::fromToken($token), 'it no longer resolves, so /verificar answers not-found');
T::same([], Busca::rowsForDocument(synthetic_cpf('919191919')), 'and /buscar no longer finds it');

// The token is not reused and not freed: the row keeps it, so nothing else can
// ever be issued under the same address.
T::same($token, (string) $recarregada->getToken(), 'the token stays on the row');

// --- a voided certificate is not a duplicate ------------------------------------------
//
// Issuing it again is usually the reason one was voided, so it must not block
// the replacement.

$validador = new Validador();
$substituta = $validador->validar([[
    'evento' => $evA, 'nome' => $prefix . ' Ana Anulada Testeson',
    'funcao' => 'competidor', 'documento' => synthetic_cpf('919191919'),
]])[0];
$codigos = array_map(static fn ($p) => $p->codigo, $substituta->problemas());
T::ok('a voided row raises no duplicate', !in_array(Problema::DUPLICADO, $codigos, true));
T::ok('and no name conflict either', !in_array(Problema::NOME_DIVERGENTE_LEVE, $codigos, true));
T::ok('so the replacement can be written', $substituta->podeGravar());

// --- voiding twice does not overwrite the first reason ----------------------------------

T::same(0, $anulacao->anular([$token], 'outro motivo'), 'an already-void certificate is skipped');
\Baja\Model\Map\ParticipanteTableMap::clearInstancePool();
T::same(
    'emitido em duplicidade',
    (string) ParticipanteQuery::create()->filterByToken($token)->findOne()->getAnuladoMotivo(),
    'and keeps the reason it was voided with'
);

// --- the admin lookup is the only place it shows -----------------------------------------

T::same(0, (new Consulta([], [], $prefix, '', Consulta::DOC_AMBOS, Consulta::ESTADO_VALIDOS))->total(), 'hidden from the default lookup');
T::same(1, (new Consulta([], [], $prefix, '', Consulta::DOC_AMBOS, Consulta::ESTADO_ANULADOS))->total(), 'listed when asked for');
T::same(1, (new Consulta([], [], $prefix, '', Consulta::DOC_AMBOS, Consulta::ESTADO_TODOS))->total(), 'and among everything');

// Asking for voided certificates is itself a filter, so the page does not call
// that search unfiltered.
T::ok('asking for voided rows counts as a filter',
    (new Consulta([], [], '', '', Consulta::DOC_AMBOS, Consulta::ESTADO_ANULADOS))->temFiltro());
T::ok('while the default does not',
    !(new Consulta([], [], '', '', Consulta::DOC_AMBOS, Consulta::ESTADO_VALIDOS))->temFiltro());

// --- restoring ---------------------------------------------------------------------------

T::same(1, $anulacao->restaurar([$token]), 'one certificate restored');
\Baja\Model\Map\ParticipanteTableMap::clearInstancePool();
$voltou = ParticipanteQuery::create()->filterByToken($token)->findOne();
T::same(null, $voltou->getAnuladoEm(), 'the void is cleared');
T::same(null, $voltou->getAnuladoPor(), 'and who did it');
T::same(null, $voltou->getAnuladoMotivo(), 'and the reason — this is what restoring costs');
T::ok('the certificate resolves again', Certificado::fromToken($token) !== null);

T::same(0, $anulacao->restaurar([$token]), 'restoring a valid certificate does nothing');

// --- several at once, in one transaction -------------------------------------------------

$cleanup();
$tokens = [];
foreach ([['Bruno', '929292929', $evA], ['Carla', '939393939', $evB], ['Diego', '949494949', $evA]] as [$n, $c, $ev]) {
    $tokens[] = (string) $gravar($n . ' Multi Testeson', synthetic_cpf($c), $ev, 'juiz')->getToken();
}

T::same(3, $anulacao->anular($tokens, 'evento cancelado'), 'three voided together');
\Baja\Model\Map\ParticipanteTableMap::clearInstancePool();
T::same(
    3,
    ParticipanteQuery::create()->filterByNome($prefix . '%', Criteria::LIKE)
        ->filterByAnuladoEm(null, Criteria::ISNOTNULL)->count(),
    'and all three carry the void'
);
T::same(3, count(Anulacao::linhas($tokens)), 'the rows are listable by token for the preview');

// A mixed list voids only what is not already void, and counts honestly.
$novo = (string) $gravar('Elena Nova Testeson', synthetic_cpf('959595959'), $evA, 'juiz')->getToken();
T::same(1, $anulacao->anular(array_merge($tokens, [$novo]), 'evento cancelado'), 'only the new one is voided');

// --- malformed tokens name nothing --------------------------------------------------------

T::same([], Anulacao::linhas(['nao-e-um-token']), 'a malformed token lists nothing');
T::same([], Anulacao::linhas([]), 'an empty list lists nothing');
T::same(0, $anulacao->anular(['nao-e-um-token'], 'motivo'), 'and voids nothing');
T::same(0, $anulacao->anular([Token::generate()], 'motivo'), 'an unknown token voids nothing');

$cleanup();
