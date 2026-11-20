<?php
/**
 * The participant's status page, /acompanhar?p={protocolo}, and the links to
 * it (BAJAAPP-88).
 *
 * What this checks, in plain words:
 *
 *  - The page tells a participant where their report is (Aberto, Em análise,
 *    Resolvido, Recusado) and, once it is closed, the reply staff wrote.
 *  - It never shows anything private from the report: not the name, the
 *    document, the email, the phone, the description, or the note staff
 *    keep for themselves. Anybody holding the link sees the page, so these
 *    checks look for each of those values in the HTML and expect not to
 *    find them.
 *  - A protocol that does not exist and one that is not even the right
 *    shape get exactly the same "not found" page, so nobody can find out
 *    from outside which protocols are real.
 *  - The receipt email and both pages shown after submitting the form
 *    carry the link.
 */

use Baja\Certificado\Documento;
use Baja\Certificado\Requerimento\Acompanhamento;
use Baja\Certificado\Requerimento\Aviso;
use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Token;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Model\Map\CertificadoRequerimentoTableMap;
use Propel\Runtime\ActiveQuery\Criteria;

T::group('acompanhamento/receipt');

// The receipt is built in memory here and never saved: writing the email's
// text needs no database. Same fixture shape as privacidade_test.php.
$emMemoria = new CertificadoRequerimento();
$emMemoria->setRequerimentoId(str_repeat('B', 22));
$emMemoria->setCaso(Caso::AUSENTE);
$emMemoria->setEventoTexto('Baja SAE Sudeste, 2009');
$emMemoria->setDocumento(synthetic_cpf('987654321'));
$emMemoria->setEmail('fulana@example.org');
$emMemoria->setCriadoEm(new DateTime('2026-10-20 10:00:00'));

$recibo = Aviso::textoDoRecibo($emMemoria);

T::ok('the receipt carries the status link for its own protocol', str_contains($recibo, Acompanhamento::url($emMemoria)));
T::ok('and the link ends in ?p= and the protocol', str_ends_with(Acompanhamento::url($emMemoria), '/acompanhar?p=' . str_repeat('B', 22)));
T::ok('it says the report can be followed at the link', str_contains($recibo, 'acompanhar a situação'));
T::ok('it says the answer comes by email when the report is closed', str_contains($recibo, 'Quando o requerimento for encerrado, a resposta chega por e-mail'));
T::ok('it still says how to ask for access to, or deletion of, contact data', str_contains($recibo, 'responda a') && str_contains($recibo, 'este e-mail citando o protocolo'));

T::group('acompanhamento/page');

if (!test_db_available()) {
    T::skip('the status page', 'BAJA_TEST_DB is not 1');
    return;
}

$prefixo = 'ZZFixtureAcomp';
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

/**
 * Save one report, with every private field filled in with a value nobody
 * would write by accident, so that finding it in a page can only mean the
 * page printed it.
 *
 * @param array<string, mixed> $campos column => value, overriding the defaults
 */
$novo = static function (array $campos = []) use ($prefixo): CertificadoRequerimento {
    $marca = bin2hex(random_bytes(4));

    $r = new CertificadoRequerimento();
    $r->setRequerimentoId(Token::generate());
    $r->setCaso(Caso::AUSENTE);
    $r->setEventoTexto('Evento de teste acompanhamento, 2009');
    $r->setDocumento(synthetic_cpf(sprintf('%09d', random_int(0, 999999999))));
    $r->setNome($prefixo . ' Nome Privado ' . $marca);
    $r->setEmail('zz-fixture-acomp-' . $marca . '@example.org');
    $r->setTelefone('+55 11 90000-' . random_int(1000, 9999));
    $r->setDescricao('Descricao privada do participante ' . $marca);
    $r->setCriadoEm(new DateTime('2026-10-20 10:00:00'));
    $r->setStatus('aberto');
    $r->setAvisadoEm(new DateTime());

    foreach ($campos as $coluna => $valor) {
        $r->setByName($coluna, $valor);
    }

    $r->save();

    return $r;
};

/** The status page for one protocol. */
$pagina = static fn (string $protocolo): array => render_page('certificado/acompanhar.php', ['p' => $protocolo]);

/**
 * The privacy rule, checked on one page: none of the report's private values
 * appear in it. The document is looked for as typed and as the receipt
 * masks it, since either would identify the person.
 */
$semDadosPrivados = static function (string $rotulo, string $html, CertificadoRequerimento $r): void {
    $privados = [
        'the name'          => (string) $r->getNome(),
        'the document'      => (string) $r->getDocumento(),
        'the masked document' => Documento::mascarar((string) $r->getDocumento()),
        'the email'         => (string) $r->getEmail(),
        'the phone'         => (string) $r->getTelefone(),
        'the description'   => (string) $r->getDescricao(),
    ];

    if ((string) $r->getResolucao() !== '') {
        $privados['the internal note'] = (string) $r->getResolucao();
    }

    foreach ($privados as $qual => $valor) {
        T::ok("{$rotulo}: the page does not show {$qual}", !str_contains($html, Baja\Certificado\Template::e($valor)) && !str_contains($html, $valor));
    }
};

/** The page rendered without a PHP warning or error in it. */
$semErros = static function (string $rotulo, string $html): void {
    T::ok("{$rotulo}: no PHP warning or error in the page", preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $html) !== 1);
};

// --- an open report ----------------------------------------------------------

$aberto = $novo();
$r = $pagina((string) $aberto->getRequerimentoId());

T::same(200, $r['status'], 'an open report: the page answers 200');
$semErros('open', $r['html']);
T::ok('open: it shows the protocol', str_contains($r['html'], (string) $aberto->getRequerimentoId()));
T::ok('open: it shows the type of report', str_contains($r['html'], Caso::resumo(Caso::AUSENTE)));
T::ok('open: it shows the event', str_contains($r['html'], 'Evento de teste acompanhamento, 2009'));
T::ok('open: it shows the date it was received', str_contains($r['html'], '20/10/2026'));
T::ok('open: the status is "Aberto"', str_contains($r['html'], '<strong>Aberto</strong>'));
T::ok('open: there is no reply section yet', !str_contains($r['html'], 'Resposta da equipe'));
T::ok('open: it says the answer will come by email', str_contains($r['html'], 'chega por') && str_contains($r['html'], 'e-mail'));
$semDadosPrivados('open', $r['html'], $aberto);

// --- one somebody has picked up -----------------------------------------------

$emAnalise = $novo(['Status' => 'em_analise']);
$r = $pagina((string) $emAnalise->getRequerimentoId());

T::ok('in review: the status is "Em análise"', str_contains($r['html'], '<strong>Em análise</strong>'));
T::ok('in review: still no reply section', !str_contains($r['html'], 'Resposta da equipe'));

// --- a closed report, with a reply and an internal note -------------------------

$resposta = "Corrigimos o seu nome no certificado.\nO link de verificação continua o mesmo.";
$resolvido = $novo([
    'Status'      => 'resolvido',
    'Resposta'    => $resposta,
    'Resolucao'   => 'Nota interna: conferido na planilha original ' . bin2hex(random_bytes(3)),
    'ResolvidoEm' => new DateTime('2026-10-25 15:30:00'),
    'RespondidoEm' => new DateTime('2026-10-25 15:30:05'),
]);
$r = $pagina((string) $resolvido->getRequerimentoId());

T::same(200, $r['status'], 'a closed report: the page answers 200');
$semErros('closed', $r['html']);
T::ok('closed: the status is "Resolvido"', str_contains($r['html'], '<strong>Resolvido</strong>'));
T::ok('closed: it shows the closing date', str_contains($r['html'], 'Encerrado em') && str_contains($r['html'], '25/10/2026'));
T::ok('closed: it shows the reply to the participant, as written', str_contains($r['html'], Baja\Certificado\Template::e($resposta)));
$semDadosPrivados('closed', $r['html'], $resolvido);

$recusado = $novo([
    'Status'      => 'recusado',
    'Resposta'    => 'O certificado está correto conforme a lista de presença do evento.',
    'ResolvidoEm' => new DateTime('2026-10-26 09:00:00'),
]);
$r = $pagina((string) $recusado->getRequerimentoId());
T::ok('refused: the status is "Recusado"', str_contains($r['html'], '<strong>Recusado</strong>'));
T::ok('refused: it shows the reply', str_contains($r['html'], 'lista de presença do evento'));

// --- a report closed before replies existed ----------------------------------------
//
// Reports closed before BAJAAPP-88 have only the internal note, written when
// staff were told it would never be sent. Their page shows the status and
// nothing under it: the old note must not suddenly become public.

$antigo = $novo([
    'Status'      => 'resolvido',
    'Resolucao'   => 'Anotacao antiga, escrita como interna ' . bin2hex(random_bytes(3)),
    'ResolvidoEm' => new DateTime('2026-09-01 12:00:00'),
]);
$r = $pagina((string) $antigo->getRequerimentoId());
T::ok('closed before replies existed: the status is shown', str_contains($r['html'], '<strong>Resolvido</strong>'));
T::ok('closed before replies existed: there is no reply section', !str_contains($r['html'], 'Resposta da equipe'));
$semDadosPrivados('closed before replies existed', $r['html'], $antigo);

// --- duplicates show their own row ------------------------------------------------
//
// A duplicate is the same report sent from another email address. Its page
// must show its own status and its own reply, never the original's: the
// original belongs to whoever sent it, who may be somebody else.

$original = $novo([
    'Status'      => 'resolvido',
    'Resposta'    => 'Resposta do original, que o duplicado nao deve ver',
    'ResolvidoEm' => new DateTime('2026-10-27 10:00:00'),
]);
$duplicadoAberto = $novo(['DuplicadoDe' => $original->getRequerimentoId()]);
$r = $pagina((string) $duplicadoAberto->getRequerimentoId());

T::ok('an open duplicate of a closed original: it shows its own status, "Aberto"', str_contains($r['html'], '<strong>Aberto</strong>'));
T::ok("and not the original's reply", !str_contains($r['html'], 'Resposta do original'));
T::ok("and not the original's protocol", !str_contains($r['html'], (string) $original->getRequerimentoId()));
$semDadosPrivados('open duplicate', $r['html'], $duplicadoAberto);
T::ok("and not the original's email either", !str_contains($r['html'], (string) $original->getEmail()));

$originalAberto = $novo();
$duplicadoFechado = $novo([
    'DuplicadoDe' => $originalAberto->getRequerimentoId(),
    'Status'      => 'recusado',
    'Resposta'    => 'Resposta propria do duplicado',
    'ResolvidoEm' => new DateTime('2026-10-28 10:00:00'),
]);
$r = $pagina((string) $duplicadoFechado->getRequerimentoId());
T::ok('a closed duplicate of an open original: it shows its own status, "Recusado"', str_contains($r['html'], '<strong>Recusado</strong>'));
T::ok('and its own reply', str_contains($r['html'], 'Resposta propria do duplicado'));

// --- not found: one answer for every wrong protocol ------------------------------------
//
// Four ways to get it wrong: the right shape but no such report, the wrong
// length, nothing at all, and the parameter sent as a list (p[]=...), which
// PHP turns into an array. All four must get a 404 and the exact same page.

$naoExiste = $pagina(Token::generate());
$curto     = $pagina('abc');
$vazio     = render_page('certificado/acompanhar.php');
$lista     = render_page('certificado/acompanhar.php', ['p' => ['x']]);

T::same(404, $naoExiste['status'], 'an unknown protocol answers 404');
T::same(404, $curto['status'], 'a malformed protocol answers 404');
T::same(404, $vazio['status'], 'no protocol at all answers 404');
T::same(404, $lista['status'], 'a protocol sent as a list answers 404');
T::ok('the not-found page says "Protocolo não encontrado"', str_contains($naoExiste['html'], 'Protocolo não encontrado'));
T::ok('an unknown and a malformed protocol get byte-identical pages', $naoExiste['html'] === $curto['html']);
T::ok('so do no protocol and a list', $naoExiste['html'] === $vazio['html'] && $naoExiste['html'] === $lista['html']);
$semErros('not found, sent as a list', $lista['html']);

T::group('acompanhamento/links');

// --- the pages shown after submitting the form ----------------------------------------
//
// The form is submitted for real here, through the page, as a browser would
// post it. Turnstile is not configured on a dev stack (and the test is
// skipped where it is), so no captcha token is needed. The address and the
// email are random each run so the per-address limit never gets in the way.

if (Baja\Util\Turnstile::habilitado()) {
    T::skip('the links on the pages after submitting', 'Turnstile is configured, so a scripted POST is refused');
    $limpar();

    return;
}

$documento = synthetic_cpf(sprintf('%09d', random_int(0, 999999999)));
$post = [
    'caso'         => Caso::AUSENTE,
    'evento'       => Baja\Certificado\Requerimento\Formulario::EVENTO_OUTRO,
    'evento_texto' => 'Evento de teste acompanhamento, 2009',
    'nome'         => $prefixo . ' Fulana Formulario',
    'documento'    => $documento,
    'email'        => 'zz-fixture-acomp-form-' . random_int(0, 999999) . '@example.org',
    'descricao'    => 'Participei do evento e o certificado nao aparece na busca.',
    'ciente'       => '1',
];
$origem = ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)];

$primeiro = render_page('certificado/requerimento.php', [], $post, $origem);
CertificadoRequerimentoTableMap::clearInstancePool();
$salvo = CertificadoRequerimentoQuery::create()->filterByDocumento($documento)->findOne();

T::ok('submitting the form saves the report', $salvo !== null);

if ($salvo !== null) {
    $link = 'href="' . Baja\Certificado\Template::e(Acompanhamento::caminho((string) $salvo->getRequerimentoId())) . '"';

    T::ok('the success page says the report was received', str_contains($primeiro['html'], 'Requerimento recebido'));
    T::ok('the success page links to the status page for this protocol', str_contains($primeiro['html'], $link));

    // The same form again, from the same email: the "já tem um requerimento
    // aberto" page. The person reading it is the one most likely to be
    // wondering what happened to the first one, so the link matters most here.
    $segundo = render_page('certificado/requerimento.php', [], $post, $origem);

    T::ok('sending it again shows the "already open" page', str_contains($segundo['html'], 'já tem um requerimento aberto'));
    T::ok('and that page links to the same status page', str_contains($segundo['html'], $link));
}

$limpar();
