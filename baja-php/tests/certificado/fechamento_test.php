<?php
/**
 * Closing a report from the staff page, and the email that tells the
 * participant (BAJAAPP-88).
 *
 * Fechamento is what the staff form's "Salvar" and "Reenviar" buttons do.
 * Instead of a real mail server it is given a small stand-in function that
 * writes down every email it was asked to send (to which report, and so to
 * which address) and answers "sent" or "failed" as the test chooses. That is
 * how these tests can ask "how many emails went out, and to whom?".
 *
 * What this checks, in plain words:
 *
 *  - A report cannot be closed without a reply to the participant.
 *  - The closing email carries the reply and the status link, and nothing
 *    private from the report.
 *  - When the email fails, the closing is still saved, the report is marked
 *    as still owing its email, and "Reenviar" sends it exactly once.
 *  - Saving again without changing the status or the reply sends nothing;
 *    reopening and closing again sends again; "em análise" never sends.
 *  - Closing a report with open duplicates closes them too, when the box is
 *    ticked, and emails each one at its own address.
 */

use Baja\Certificado\Documento;
use Baja\Certificado\Requerimento\Acompanhamento;
use Baja\Certificado\Requerimento\Aviso;
use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Requerimento\Fechamento;
use Baja\Certificado\Token;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Model\Map\CertificadoRequerimentoTableMap;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('fechamento/email');

// The email's text needs no database: a report built in memory is enough.
$emMemoria = new CertificadoRequerimento();
$emMemoria->setRequerimentoId(str_repeat('C', 22));
$emMemoria->setCaso(Caso::AUSENTE);
$emMemoria->setEventoTexto('Baja SAE Sul, 2011');
$emMemoria->setDocumento(synthetic_cpf('192837465'));
$emMemoria->setNome('ZZFixture Nome Que Nao Vai No Email');
$emMemoria->setEmail('fulano-email@example.org');
$emMemoria->setTelefone('+55 11 98888-7777');
$emMemoria->setDescricao('Texto privado que o participante escreveu');
$emMemoria->setResolucao('Nota interna que nunca sai daqui');
$emMemoria->setResposta('Incluimos o seu certificado. Ele ja aparece na busca.');
$emMemoria->setStatus('resolvido');
$emMemoria->setCriadoEm(new DateTime('2026-10-20 10:00:00'));

$texto = Aviso::textoDaResposta($emMemoria);

T::ok('the closing email carries the reply', str_contains($texto, 'Incluimos o seu certificado. Ele ja aparece na busca.'));
T::ok('and the status link', str_contains($texto, Acompanhamento::url($emMemoria)));
T::ok('and the protocol', str_contains($texto, str_repeat('C', 22)));
T::ok('and the type of report', str_contains($texto, Caso::resumo(Caso::AUSENTE)));
T::ok('and the event', str_contains($texto, 'Baja SAE Sul, 2011'));
T::ok('and the new status in words', str_contains($texto, 'Resolvido'));
T::ok('but not the name', !str_contains($texto, 'ZZFixture Nome Que Nao Vai No Email'));
T::ok('or the document', !str_contains($texto, (string) $emMemoria->getDocumento()));
T::ok('or the document masked, as the receipt shows it', !str_contains($texto, Documento::mascarar((string) $emMemoria->getDocumento())));
T::ok('or the email address', !str_contains($texto, 'fulano-email@example.org'));
T::ok('or the phone', !str_contains($texto, '98888-7777'));
T::ok("or the participant's description", !str_contains($texto, 'Texto privado que o participante escreveu'));
T::ok('or the internal note', !str_contains($texto, 'Nota interna que nunca sai daqui'));

T::group('fechamento/salvar');

if (!test_db_available()) {
    T::skip('closing reports', 'BAJA_TEST_DB is not 1');
    return;
}

$prefixo = 'ZZFixtureFechamento';
$limpar  = static function () use ($prefixo): void {
    // Duplicates first: the link between rows is a foreign key.
    CertificadoRequerimentoQuery::create()
        ->filterByNome($prefixo . '%', Criteria::LIKE)
        ->filterByDuplicadoDe(null, Criteria::ISNOTNULL)
        ->delete();
    CertificadoRequerimentoQuery::create()->filterByNome($prefixo . '%', Criteria::LIKE)->delete();
    CertificadoRequerimentoTableMap::clearInstancePool();
};
$limpar();

$staff = test_user_id();

/** Save one open report with its own random email. */
$novo = static function (?string $duplicadoDe = null) use ($prefixo): CertificadoRequerimento {
    $r = new CertificadoRequerimento();
    $r->setRequerimentoId(Token::generate());
    $r->setCaso(Caso::AUSENTE);
    $r->setEventoTexto('Evento de teste fechamento, 2009');
    $r->setDocumento(synthetic_cpf(sprintf('%09d', random_int(0, 999999999))));
    $r->setNome($prefixo . ' Fulana Testeson');
    $r->setEmail('zz-fixture-fechamento-' . bin2hex(random_bytes(4)) . '@example.org');
    $r->setDescricao('Participei do evento e o certificado nao aparece na busca.');
    $r->setCriadoEm(new DateTime());
    $r->setStatus('aberto');
    $r->setAvisadoEm(new DateTime());
    $r->setDuplicadoDe($duplicadoDe);
    $r->save();

    return $r;
};

/** The row as it is in the database now, not as this process remembers it. */
$recarregar = static function (CertificadoRequerimento $r): CertificadoRequerimento {
    CertificadoRequerimentoTableMap::clearInstancePool();

    return CertificadoRequerimentoQuery::create()->findPk($r->getRequerimentoId());
};

/**
 * A Fechamento whose emails are only written down, in $enviados, as the
 * address each was sent to. $falhar decides whether the "relay" accepts.
 * Both are passed by reference so the test can read and change them.
 */
$montar = static function (array &$enviados, bool &$falhar): Fechamento {
    return new Fechamento(static function (CertificadoRequerimento $r) use (&$enviados, &$falhar): bool {
        $enviados[] = (string) $r->getEmail();

        return !$falhar;
    });
};

$enviados   = [];
$falhar     = false;
$fechamento = $montar($enviados, $falhar);

// --- closing needs a reply ---------------------------------------------------------

$r = $novo();
$s = $fechamento->salvar($r, $staff, 'resolvido', '', 'só uma nota interna', false);

T::ok('closing as "resolvido" with no reply is refused', !$s->salvou());
T::ok('with a message that asks for the reply', str_contains($s->erro, 'resposta ao participante'));
T::same('aberto', (string) $recarregar($r)->getStatus(), 'and the report is still open');
T::same([], $enviados, 'and nothing was sent');

$s = $fechamento->salvar($r, $staff, 'recusado', '', '', false);
T::ok('"recusado" with no reply is refused too', !$s->salvou());

$s = $fechamento->salvar($r, $staff, 'resolvido', str_repeat('a', Fechamento::TEXTO_MAX + 1), '', false);
T::ok('a reply longer than the column is refused', !$s->salvou());

$s = $fechamento->salvar($r, $staff, 'nao-existe', 'x', '', false);
T::ok('a status that does not exist is refused', !$s->salvou());

// --- "em análise" sends nothing ---------------------------------------------------------

$enviados = [];
$s = $fechamento->salvar($r, $staff, 'em_analise', '', '', false);
T::ok('moving to "em análise" needs no reply', $s->salvou());
T::same([], $enviados, 'and sends nothing');

// --- closing sends one email, to the report's own address -----------------------------------

$enviados = [];
$s = $fechamento->salvar($r, $staff, 'resolvido', 'Corrigimos o certificado.', 'Conferido na planilha.', false);
$depois = $recarregar($r);

T::ok('closing with a reply is saved', $s->salvou());
T::same('resolvido', (string) $depois->getStatus(), 'the report is now "resolvido"');
T::same('Corrigimos o certificado.', (string) $depois->getResposta(), 'the reply is stored');
T::same('Conferido na planilha.', (string) $depois->getResolucao(), 'and so is the internal note, separately');
T::same([(string) $r->getEmail()], $enviados, 'one email, to the address in the report');
T::ok('the relay accepted it, so respondido_em is set', $depois->getRespondidoEm() !== null);
T::same($staff, (int) $depois->getResolvidoPor(), 'and the report records who closed it');
T::ok('and when', $depois->getResolvidoEm() !== null);
T::ok('nothing is owed any more', !Fechamento::respostaPendente($depois));

// --- saving again without a change sends nothing --------------------------------------------

$enviados = [];
$fechadoEm = $depois->getResolvidoEm('Y-m-d H:i:s');
$fechamento->salvar($depois, $staff, 'resolvido', 'Corrigimos o certificado.', 'Conferido na planilha.', false);
T::same([], $enviados, 'saving a closed report with the same status and reply sends nothing');

$fechamento->salvar($depois, $staff, 'resolvido', 'Corrigimos o certificado.', 'Nota interna editada depois.', false);
T::same([], $enviados, 'editing only the internal note sends nothing either');
T::same('Nota interna editada depois.', (string) $recarregar($r)->getResolucao(), 'though the note is saved');
T::same($fechadoEm, $recarregar($r)->getResolvidoEm('Y-m-d H:i:s'), 'and the closing date stays the original one');

// --- changing what the participant would read sends again -----------------------------------

$fechamento->salvar($recarregar($r), $staff, 'resolvido', 'Corrigimos o certificado e o nome.', 'Nota interna editada depois.', false);
T::same([(string) $r->getEmail()], $enviados, 'a different reply on a closed report sends it again');

$enviados = [];
$fechamento->salvar($recarregar($r), $staff, 'recusado', 'Corrigimos o certificado e o nome.', '', false);
T::same([(string) $r->getEmail()], $enviados, 'so does moving from "resolvido" to "recusado"');

// --- reopening sends nothing; closing again sends again -----------------------------------------

$enviados = [];
$fechamento->salvar($recarregar($r), $staff, 'aberto', 'Corrigimos o certificado e o nome.', '', false);
$reaberto = $recarregar($r);
T::same([], $enviados, 'reopening sends nothing');
T::same(null, $reaberto->getResolvidoPor(), 'and clears who closed it');
T::same(null, $reaberto->getRespondidoEm(), 'and clears respondido_em, since the next closing owes a new email');

$fechamento->salvar($reaberto, $staff, 'resolvido', 'Corrigimos o certificado e o nome.', '', false);
T::same([(string) $r->getEmail()], $enviados, 'closing it again with the same reply sends again');

T::group('fechamento/falha');

// --- a failed send: the closing stands, the email is owed ------------------------------------------

$enviados = [];
$falhar   = true;
$r = $novo();
$s = $fechamento->salvar($r, $staff, 'resolvido', 'Resposta que o relay recusou.', '', false);
$depois = $recarregar($r);

T::ok('when the email fails, the closing is still saved', $s->salvou() && (string) $depois->getStatus() === 'resolvido');
T::same(1, count($enviados), 'one send was attempted');
T::same([(string) $r->getRequerimentoId()], $s->naoEnviados, 'and the result names the report whose email failed');
T::same(null, $depois->getRespondidoEm(), 'respondido_em stays empty');
T::ok('so the staff page will offer "Reenviar"', Fechamento::respostaPendente($depois));

// --- "Reenviar" sends once --------------------------------------------------------------------------

$falhar   = false;
$enviados = [];
$s = $fechamento->reenviar($depois);
T::ok('Reenviar works once the relay is back', $s->salvou() && $s->enviados() === 1);
T::same([(string) $r->getEmail()], $enviados, 'and sends exactly one email, to the report\'s address');
T::ok('and sets respondido_em', $recarregar($r)->getRespondidoEm() !== null);

$s = $fechamento->reenviar($recarregar($r));
T::ok('pressing Reenviar again is refused, since nothing is owed now', !$s->salvou());
T::same(1, count($enviados), 'and sends nothing more');

// A report closed before BAJAAPP-88 has no reply to send, so it owes nothing
// and Reenviar has nothing to do.
$antigo = $novo();
$antigo->setStatus('resolvido');
$antigo->setResolucao('Fechado antes das respostas existirem');
$antigo->setResolvidoEm(new DateTime());
$antigo->save();
T::ok('a report closed before replies existed owes no email', !Fechamento::respostaPendente($antigo));
T::ok('and Reenviar on it sends nothing', !$fechamento->reenviar($antigo)->salvou() && count($enviados) === 1);

T::group('fechamento/duplicados');

// --- an original with two open duplicates, box ticked: three closed, three emails -------------------
//
// The duplicates came from other email addresses (that is what makes them
// duplicates rather than the same press of a button), so each email must go
// to its own row's address, and each row records its own closing.

$enviados = [];
$original = $novo();
$dupA     = $novo((string) $original->getRequerimentoId());
$dupB     = $novo((string) $original->getRequerimentoId());

T::same(2, count(Fechamento::duplicadosAbertos($original)), 'the original has two open duplicates for the form to offer');

$s = $fechamento->salvar($original, $staff, 'resolvido', 'Resposta para todos.', 'Nota para todos.', true);

T::ok('closing the original with the box ticked is saved', $s->salvou());
T::same(2, $s->fechadosJunto, 'and reports two duplicates closed with it');
foreach (['original' => $original, 'first duplicate' => $dupA, 'second duplicate' => $dupB] as $qual => $linha) {
    $agora = $recarregar($linha);
    T::same('resolvido', (string) $agora->getStatus(), "the {$qual} is closed");
    T::same('Resposta para todos.', (string) $agora->getResposta(), "the {$qual} has the same reply");
    T::same('Nota para todos.', (string) $agora->getResolucao(), "the {$qual} has the same internal note");
    T::same($staff, (int) $agora->getResolvidoPor(), "the {$qual} records who closed it");
    T::ok("the {$qual} records when", $agora->getResolvidoEm() !== null);
    T::ok("the {$qual}'s email was sent", $agora->getRespondidoEm() !== null);
}

$esperados = [(string) $original->getEmail(), (string) $dupA->getEmail(), (string) $dupB->getEmail()];
T::same(3, count($enviados), 'three emails were sent');
T::same(3, count(array_unique($enviados)), 'to three different addresses');
T::same([], array_values(array_diff($esperados, $enviados)), 'each one to its own report\'s address');
T::same([], Fechamento::duplicadosAbertos($original), 'and no open duplicate is left');

// --- the same, box unticked: only the original ---------------------------------------------------------

$enviados = [];
$original = $novo();
$dupA     = $novo((string) $original->getRequerimentoId());
$dupB     = $novo((string) $original->getRequerimentoId());

$s = $fechamento->salvar($original, $staff, 'recusado', 'Resposta só para o original.', '', false);

T::same(0, $s->fechadosJunto, 'with the box unticked, no duplicate is closed');
T::same('recusado', (string) $recarregar($original)->getStatus(), 'the original is closed');
T::same('aberto', (string) $recarregar($dupA)->getStatus(), 'the first duplicate is still open');
T::same('aberto', (string) $recarregar($dupB)->getStatus(), 'the second duplicate is still open');
T::same([(string) $original->getEmail()], $enviados, 'and only the original\'s address got an email');

// --- closing a duplicate on its own closes only that one -----------------------------------------------------

$enviados = [];
$fechamento->salvar($recarregar($dupA), $staff, 'resolvido', 'Resposta só para este duplicado.', '', true);
T::same('resolvido', (string) $recarregar($dupA)->getStatus(), 'closing a duplicate by itself closes it');
T::same('aberto', (string) $recarregar($dupB)->getStatus(), 'and leaves the other duplicate open');
T::same('recusado', (string) $recarregar($original)->getStatus(), 'and does not touch the original');
T::same([(string) $dupA->getEmail()], $enviados, 'one email, to that duplicate\'s address');

// --- duplicates that are already closed are left alone -----------------------------------------------------------

$enviados = [];
$original = $novo();
$dupFechado = $novo((string) $original->getRequerimentoId());
$fechamento->salvar($dupFechado, $staff, 'recusado', 'Resposta anterior do duplicado.', '', false);
$enviados = [];

$fechamento->salvar($original, $staff, 'resolvido', 'Resposta do original.', '', true);
T::same('Resposta anterior do duplicado.', (string) $recarregar($dupFechado)->getResposta(), 'a duplicate already closed keeps its own reply');
T::same([(string) $original->getEmail()], $enviados, 'and is not emailed again');

// --- one duplicate's email fails: the rest still go, and the failure is named --------------------------------------

$original = $novo();
$dupA     = $novo((string) $original->getRequerimentoId());
$dupB     = $novo((string) $original->getRequerimentoId());
$enviados = [];

$falhaSo = new Fechamento(static function (CertificadoRequerimento $r) use (&$enviados, $dupA): bool {
    $enviados[] = (string) $r->getEmail();

    return $r->getRequerimentoId() !== $dupA->getRequerimentoId();
});
$s = $falhaSo->salvar($original, $staff, 'resolvido', 'Resposta para todos.', '', true);

T::same(3, count($enviados), 'when one duplicate\'s email fails, all three are still attempted');
T::same([(string) $dupA->getRequerimentoId()], $s->naoEnviados, 'the failed one is named by its protocol');
T::ok('it owes its email, and only it', Fechamento::respostaPendente($recarregar($dupA))
    && !Fechamento::respostaPendente($recarregar($original))
    && !Fechamento::respostaPendente($recarregar($dupB)));
T::same('resolvido', (string) $recarregar($dupA)->getStatus(), 'and it is closed all the same');

$limpar();
