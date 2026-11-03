<?php

use Baja\Certificado\Requerimento\Aviso;
use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Requerimento\Formulario;
use Baja\Certificado\Requerimento\Limite;
use Baja\Certificado\Requerimento\Recepcao;
use Baja\Util\Mailer;
use Baja\Util\Turnstile;

T::group('requerimento/caso');

// The valueSet in schema.xml is stored as a TINYINT holding the index, so the
// order of this list is permanent — reordering it silently relabels every row
// already written. Pinned here so that a reorder fails a test rather than a
// year of reports.
T::same(['incorreto', 'ausente', 'indevido'], Caso::TODOS, 'the case order is the stored order');

T::ok('a known case is valid', Caso::valido(Caso::INCORRETO));
T::ok('an invented case is not', !Caso::valido('qualquer'));
T::ok('an empty case is not', !Caso::valido(''));

// Which cases start from a /buscar result, and which cannot.
T::ok('a wrong certificate needs a token', Caso::exigeToken(Caso::INCORRETO));
T::ok('an undue certificate needs a token', Caso::exigeToken(Caso::INDEVIDO));
T::ok('a missing certificate cannot have one', !Caso::exigeToken(Caso::AUSENTE));
T::ok('a missing certificate needs an event', Caso::exigeEvento(Caso::AUSENTE));
T::ok('a wrong certificate does not', !Caso::exigeEvento(Caso::INCORRETO));

// Every case has a label and an explanation; a missing one renders as an
// empty radio button, which is how a case silently stops being offered.
foreach (Caso::TODOS as $caso) {
    T::ok("{$caso} has a label", (Caso::rotulos()[$caso] ?? '') !== '');
    T::ok("{$caso} has an explanation", (Caso::explicacoes()[$caso] ?? '') !== '');
    T::ok("{$caso} has a short form", Caso::resumo($caso) !== $caso);
}

T::group('requerimento/email');

// Header injection through the address field. This form is public and the
// address goes into a Reply-To and an envelope recipient.
T::ok('a CR is refused', !Mailer::enderecoValido("a@b.com\rBcc: victim@c.com"));
T::ok('an LF is refused', !Mailer::enderecoValido("a@b.com\nBcc: victim@c.com"));
T::ok('an empty address is refused', !Mailer::enderecoValido(''));
T::ok('a bare word is refused', !Mailer::enderecoValido('nao-e-um-email'));
T::ok('an over-long address is refused', !Mailer::enderecoValido(str_repeat('a', 250) . '@b.com'));
T::ok('an ordinary address is accepted', Mailer::enderecoValido('fulano@example.org'));

T::group('requerimento/turnstile');

if (Turnstile::habilitado()) {
    T::skip('unconfigured Turnstile', 'TURNSTILE_* is set in this environment');
} else {
    // Unconfigured must degrade, never fail closed: the alternative is that a
    // missing variable takes down the only structured channel somebody has
    // for getting their own certificate corrected.
    T::ok('an unconfigured check does not block a submission', Turnstile::verificar(''));
    T::same('', Turnstile::siteKey(), 'an unconfigured site key is empty');
}

T::group('requerimento/formulario');

/** The event list the form offers, as the page builds it. */
$eventos = ['26BR' => 'Baja SAE BRASIL Nacional 2026'];

/** A complete, valid "missing certificate" submission. */
$valido = [
    'caso'      => Caso::AUSENTE,
    'evento'    => '26BR',
    'nome'      => 'Fulano de Tal Silva',
    'documento' => synthetic_cpf('123456789'),
    'email'     => 'fulano@example.org',
    'descricao' => 'Participei da etapa e o certificado nao aparece na busca.',
    'ciente'    => '1',
];

$f = new Formulario($valido, $eventos);
T::ok('a complete submission passes', $f->valido(), implode(' | ', $f->erros()));
T::same('26BR', $f->eventoCodigo(), 'the chosen event is stored');
T::same(null, $f->eventoTexto(), 'a listed event stores no free text');
T::same(null, $f->token(), 'a missing certificate stores no token');
T::same(null, $f->telefone(), 'an omitted phone is null, not an empty string');

// One required field at a time, so a failure names the rule that broke.
foreach (['caso', 'nome', 'documento', 'email', 'descricao', 'ciente'] as $campo) {
    $faltando = $valido;
    unset($faltando[$campo]);
    $f = new Formulario($faltando, $eventos);
    T::ok("{$campo} is required", !$f->valido());
}

// The agreement is a checkbox: absent and present-but-not-"1" are the same
// answer, and neither is consent.
$f = new Formulario(['ciente' => 'sim'] + $valido, $eventos);
T::ok('only the exact checkbox value counts as agreement', $f->erro('ciente') !== '');

// "Nome completo" has to mean something, and it means the same thing here as
// on /buscar — the same tokenizer decides, so the two forms cannot disagree.
$f = new Formulario(['nome' => 'Fulano'] + $valido, $eventos);
T::ok('a single name is not a full name', $f->erro('nome') !== '');
$f = new Formulario(['nome' => 'Ana de Souza'] + $valido, $eventos);
T::same('', $f->erro('nome'), 'connectives do not count as a second name, but Souza does');

// Check digits are deliberately not verified: rejecting an invalid CPF turns
// away every foreign participant and everyone whose CPF was mistyped at
// registration, which is the population this form exists for.
$f = new Formulario(['documento' => '00000000000'] + $valido, $eventos);
T::same('', $f->erro('documento'), 'an invalid CPF is still accepted');
$f = new Formulario(['documento' => 'AB1234567'] + $valido, $eventos);
T::same('', $f->erro('documento'), 'a passport is accepted');
$f = new Formulario(['documento' => 'drop table;'] + $valido, $eventos);
T::ok('punctuation outside a document is refused', $f->erro('documento') !== '');

$f = new Formulario(['email' => "a@b.com\nBcc: x@y.com"] + $valido, $eventos);
T::ok('an address carrying a header is refused', $f->erro('email') !== '');

$f = new Formulario(['descricao' => 'errado'] + $valido, $eventos);
T::ok('a one-word description is refused', $f->erro('descricao') !== '');

$f = new Formulario(['descricao' => str_repeat('a', 2001)] + $valido, $eventos);
T::ok('an over-long description is refused', $f->erro('descricao') !== '');

$f = new Formulario(['telefone' => '(51) 99999-0000'] + $valido, $eventos);
T::same('', $f->erro('telefone'), 'a phone as people write it is accepted');
$f = new Formulario(['telefone' => 'me liga'] + $valido, $eventos);
T::ok('a phone that is not a phone is refused', $f->erro('telefone') !== '');

// The event, which is the field that has to survive an event this system has
// never heard of.
$f = new Formulario(['evento' => 'ZZZZ'] + $valido, $eventos);
T::ok('an event outside the list is refused', $f->erro('evento') !== '');

$f = new Formulario(['evento' => Formulario::EVENTO_OUTRO] + $valido, $eventos);
T::ok('"outro" with nothing typed is refused', $f->erro('evento') !== '');

$naLista = ['evento' => Formulario::EVENTO_OUTRO, 'evento_texto' => 'Baja SAE Sudeste, 2009'] + $valido;
$f = new Formulario($naLista, $eventos);
T::ok('an event that predates the system is accepted', $f->valido(), implode(' | ', $f->erros()));
T::same(null, $f->eventoCodigo(), 'an unlisted event has no code');
T::same('Baja SAE Sudeste, 2009', $f->eventoTexto(), 'an unlisted event keeps what was typed');

// The two cases that must start from a /buscar result. No database is touched
// by any of these: a malformed token is refused before a query.
foreach ([Caso::INCORRETO, Caso::INDEVIDO] as $caso) {
    $f = new Formulario(['caso' => $caso] + $valido, $eventos);
    T::ok("{$caso} without a token is refused", $f->erro('token') !== '');

    $f = new Formulario(['caso' => $caso, 'token' => 'curto-demais'] + $valido, $eventos);
    T::ok("{$caso} with a malformed token is refused", $f->erro('token') !== '');
}

// A token on a case that does not use one is ignored rather than rejected —
// it is what the hidden field still carries when somebody changes their mind
// about which case they are reporting.
$f = new Formulario(['token' => str_repeat('A', 22)] + $valido, $eventos);
T::ok('a stray token on a missing-certificate report is ignored', $f->valido());
T::same(null, $f->token(), 'and is not stored');

// Values are given back as typed, so a failed submission does not cost
// somebody the paragraph they wrote.
$f = new Formulario(['email' => 'nao-e-um-email'] + $valido, $eventos);
T::same($valido['descricao'], $f->valor('descricao'), 'a rejected form keeps the description');

T::group('requerimento/limite');

// Limite caps how often one address (IP) or one email may submit the form:
// at most 5 per IP and 3 per email per hour. It keeps its counters in Redis,
// so these tests need a Redis server and are skipped without one.

if (getenv('REDIS_HOST') === false) {
    T::skip('submission limit', 'REDIS_HOST is not set');
} else {
    // Distinct per run, so a counter left over from an earlier run cannot
    // make this pass or fail spuriously.
    $origem = '203.0.113.' . random_int(1, 254);
    $email  = 'zz-fixture-' . random_int(0, 999999) . '@example.org';

    T::same(null, Limite::esperaSegundos($origem, $email), 'a fresh caller may submit');

    // reservar() is what the page calls once a submission has passed every
    // other check. It answers null for "go ahead" (and counts the
    // submission), or a number of seconds to wait.
    //
    // The email ceiling is the lower of the two, and it is the one that
    // matters: every submission mails the address typed into the form, and
    // nothing proves it belongs to whoever is typing.
    for ($i = 1; $i <= 3; $i++) {
        T::same(null, Limite::reservar($origem, $email), "reservation $i of 3 for one email is granted");
    }

    $espera = Limite::reservar($origem, $email);
    T::ok('the fourth reservation for one email is refused', $espera !== null);
    T::ok('with a wait inside the one-hour window', $espera !== null && $espera > 0 && $espera <= 3600, var_export($espera, true));
    T::ok('and the cheap read-only check now says to wait too', Limite::esperaSegundos($origem, $email) !== null);

    // And the wait must not follow the address to somebody else's mailbox, or
    // one abuser locks out a shared connection's worth of participants for an
    // hour. The address ceiling is higher precisely so that it is the email
    // that trips first.
    $outroEmail = 'zz-fixture-' . random_int(0, 999999) . '@example.org';
    T::same(null, Limite::esperaSegundos('', $outroEmail), 'another email is unaffected');

    // A refused reservation gives back what it took. The fourth try above was
    // refused because of the email, so it must not have used up one of the
    // IP's five slots: three were taken, two are left.
    $origem2 = '198.51.100.' . random_int(1, 254);
    for ($i = 0; $i < 3; $i++) {
        Limite::reservar($origem2, 'zz-fixture-a-' . random_int(0, 999999) . '@example.org');
    }
    $cheio = 'zz-fixture-cheio-' . random_int(0, 999999) . '@example.org';
    for ($i = 0; $i < 3; $i++) {
        Limite::reservar('', $cheio);
    }
    T::ok('an email over its limit is refused from any address', Limite::reservar($origem2, $cheio) !== null);
    T::same(null, Limite::reservar($origem2, 'zz-fixture-b-' . random_int(0, 999999) . '@example.org'), 'the refusal did not use up a slot of the address: the 4th is granted');
    T::same(null, Limite::reservar($origem2, 'zz-fixture-c-' . random_int(0, 999999) . '@example.org'), 'and so is the 5th');
    T::ok('and the 6th from that address is refused', Limite::reservar($origem2, 'zz-fixture-d-' . random_int(0, 999999) . '@example.org') !== null);

    // The race this replaced (BAJAAPP-89): six requests arriving together
    // all read the counter before any of them added one, so all six passed.
    // Here six separate PHP processes reserve for one email at the same
    // instant; only three may get through.
    $corrida = 'zz-fixture-corrida-' . random_int(0, 999999) . '@example.org';
    $inicio  = microtime(true) + 1.0;
    $codigo  = sprintf(
        'require %s; while (microtime(true) < %F) { usleep(500); } echo Baja\Certificado\Requerimento\Limite::reservar("", %s) === null ? "ok" : "espera";',
        var_export(__DIR__ . '/../../vendor/autoload.php', true),
        $inicio,
        var_export($corrida, true)
    );
    $processos = [];
    $saidas    = [];
    for ($i = 0; $i < 6; $i++) {
        $processos[$i] = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w']], $pipes);
        $saidas[$i]    = $pipes[1];
    }
    $concedidas = 0;
    foreach ($processos as $i => $processo) {
        if (trim(stream_get_contents($saidas[$i])) === 'ok') {
            $concedidas++;
        }
        proc_close($processo);
    }
    T::same(3, $concedidas, 'six simultaneous reservations for one email: exactly three are granted');
}

// When Redis is down, the limit switches itself off instead of blocking
// everyone: a broken cache must not stop somebody reporting a problem with
// their own certificate. Simulated by pointing Limite at a port where nothing
// is listening.
$redisPortaAntes = $_ENV['REDIS_PORT'] ?? null;
$_ENV['REDIS_PORT'] = '1';
Limite::reset();

T::same(null, Limite::esperaSegundos('203.0.113.9', 'zz@example.org'), 'with Redis unreachable, the check lets the submission through');
$todasConcedidas = true;
for ($i = 0; $i < 10; $i++) {
    $todasConcedidas = $todasConcedidas && Limite::reservar('203.0.113.9', 'zz@example.org') === null;
}
T::ok('and every reservation is granted, however many', $todasConcedidas);

if ($redisPortaAntes === null) {
    unset($_ENV['REDIS_PORT']);
} else {
    $_ENV['REDIS_PORT'] = $redisPortaAntes;
}
Limite::reset();

T::group('requerimento/recepcao');

// Recepcao is everything POST /requerimento does, in order: the limit, the
// trap field, the captcha, the form, the duplicate check, and the two emails.
// Instead of the real captcha and real emails it is given small stand-in
// functions that just count how often they were called, so these tests can
// ask "how many receipts were sent?" without a mail server.

if (!test_db_available()) {
    T::skip('submission flow', 'BAJA_TEST_DB is not 1');
    return;
}

$eventoRecepcao = \Baja\Model\EventoQuery::create()->findOne();
if ($eventoRecepcao === null) {
    T::skip('submission flow', 'need at least one event');
    return;
}
$eventosRecepcao = [(string) $eventoRecepcao->getEventoId() => 'Evento de teste'];

$prefixoRecepcao = 'ZZFixtureRecepcao';
$limparRecepcao  = static function () use ($prefixoRecepcao): void {
    \Baja\Model\CertificadoRequerimentoQuery::create()
        ->filterByNome($prefixoRecepcao . '%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)
        ->delete();
    \Baja\Model\Map\CertificadoRequerimentoTableMap::clearInstancePool();
};
$limparRecepcao();

/**
 * A Recepcao whose captcha answers $captchaPassa and whose emails are only
 * counted. The counters are passed by reference so the test can read them.
 */
$montar = static function (bool $captchaPassa, int &$recibos, int &$alertas, array &$protocolosRecibos = []): Recepcao {
    return new Recepcao(
        static fn (string $token, string $origem): bool => $captchaPassa,
        static function ($r) use (&$recibos, &$protocolosRecibos): bool {
            $recibos++;
            $protocolosRecibos[] = (string) $r->getRequerimentoId();

            return true;
        },
        static function ($r) use (&$alertas): bool {
            $alertas++;

            return true;
        }
    );
};

// A fresh, valid "my certificate is missing" submission, with its own random
// document and email so earlier runs cannot interfere.
$novoPost = static function () use ($prefixoRecepcao, $eventosRecepcao): array {
    return [
        'caso'      => Caso::AUSENTE,
        'evento'    => (string) array_key_first($eventosRecepcao),
        'nome'      => $prefixoRecepcao . ' Fulana Testeson',
        'documento' => synthetic_cpf(sprintf('%09d', random_int(0, 999999999))),
        'email'     => 'zz-fixture-recepcao-' . random_int(0, 999999) . '@example.org',
        'descricao' => 'Participei do evento e o certificado nao aparece na busca.',
        'ciente'    => '1',
    ];
};

$linhas = static function (string $documento): int {
    \Baja\Model\Map\CertificadoRequerimentoTableMap::clearInstancePool();

    return \Baja\Model\CertificadoRequerimentoQuery::create()->filterByDocumento($documento)->count();
};

$origemNova = static fn (): string => '192.0.2.' . random_int(1, 254);

// --- six presses of the button: one row, one receipt, one staff alert --------------

$recibos = 0;
$alertas = 0;
$recepcao = $montar(true, $recibos, $alertas);
$post     = $novoPost();
$origem   = $origemNova();

$respostas = [];
for ($i = 0; $i < 6; $i++) {
    $respostas[] = $recepcao->receber($post, $origem, $eventosRecepcao);
}

T::same(1, $linhas($post['documento']), 'six submissions of the same report: one row');
T::same(1, $recibos, 'one receipt to the participant');
T::same(1, $alertas, 'one alert to staff');
T::ok('the first press gets a protocol', is_string($respostas[0]->protocolo) && $respostas[0]->protocolo !== '');
T::ok('and is not told it was already open', !$respostas[0]->jaAberto);

// Presses 2 to 6 must all see the "já tem um requerimento aberto" page with
// the first protocol. None of them may be told "Muitos envios": a repeated
// press is found before the limit is counted, so it does not use up a slot.
for ($i = 1; $i < 6; $i++) {
    $r = $respostas[$i];
    T::ok(
        'press ' . ($i + 1) . ' is told the report is already open, with the first protocol',
        $r->jaAberto && $r->protocolo === $respostas[0]->protocolo
    );
    T::same(null, $r->espera, 'press ' . ($i + 1) . ' is not told to wait');
}

// The repeated presses took no slot, so this email can still send two more
// different reports within the hour (the limit is 3, and press 1 used one).
$outrasAceitas = 0;
for ($i = 0; $i < 2; $i++) {
    $outro = ['documento' => synthetic_cpf(sprintf('%09d', random_int(0, 999999999)))] + $post;
    $r = $recepcao->receber($outro, $origem, $eventosRecepcao);
    if ($r->protocolo !== null && $r->protocolo !== '' && !$r->jaAberto) {
        $outrasAceitas++;
    }
}
T::same(2, $outrasAceitas, 'the repeated presses used no slot: two more reports from the same email still go through');

// --- the same report from another email: a second row, a receipt, no alert ----------

$recibos = 0;
$alertas = 0;
$protocolosRecibos = [];
$recepcao = $montar(true, $recibos, $alertas, $protocolosRecibos);
$post     = $novoPost();

$primeira = $recepcao->receber($post, $origemNova(), $eventosRecepcao);
$segunda  = $recepcao->receber(['email' => 'zz-fixture-outra-' . random_int(0, 999999) . '@example.org'] + $post, $origemNova(), $eventosRecepcao);

T::same(2, $linhas($post['documento']), 'a second email gives a second row');
T::same(2, $recibos, 'both senders get a receipt');
T::same(1, $alertas, 'but staff are alerted only once');
T::ok('the second sender is not told it was already open', !$segunda->jaAberto);

// The privacy rule: whoever sent the second one may be somebody else typing
// another person's document. Nothing they see may reveal the first report.
T::notSame($primeira->protocolo, $segunda->protocolo, 'the second sender is shown their own protocol, never the first one');
T::same([$primeira->protocolo, $segunda->protocolo], $protocolosRecibos, 'and each receipt carries its own protocol');

$linhaSegunda = \Baja\Model\CertificadoRequerimentoQuery::create()->findPk($segunda->protocolo);
T::same($primeira->protocolo, (string) $linhaSegunda->getDuplicadoDe(), 'the second row points at the first');
T::ok('and is marked as dealt with, so the staff page does not report a failed alert', $linhaSegunda->getAvisadoEm() !== null);

$recibo = Aviso::textoDoRecibo($linhaSegunda);
T::ok('the second receipt does not mention the first protocol', !str_contains($recibo, (string) $primeira->protocolo));
T::ok('or the first email address', !str_contains($recibo, $post['email']));

// --- the limit is reserved after the captcha and the form, not before ---------------

// Five failed captchas from one address and one email...
$recibos = 0;
$alertas = 0;
$semCaptcha = $montar(false, $recibos, $alertas);
$post       = $novoPost();
$origem     = $origemNova();
for ($i = 0; $i < 5; $i++) {
    $r = $semCaptcha->receber($post, $origem, $eventosRecepcao);
}
T::ok('a failed captcha is refused with a message', $r->avisoErro !== '' && $r->protocolo === null);

// ...then five forms with a mistake in them...
$comCaptcha = $montar(true, $recibos, $alertas);
for ($i = 0; $i < 5; $i++) {
    $r = $comCaptcha->receber(['descricao' => 'curta'] + $post, $origem, $eventosRecepcao);
}
T::ok('a form with an error is shown again', $r->formulario !== null && !$r->formulario->valido());
T::same(0, $linhas($post['documento']), 'neither wrote anything');

// ...and three real, different reports from that same address and email
// still go through. If the failures above had counted, the email (3 per hour)
// would already be used up.
$aceitas = 0;
for ($i = 0; $i < 3; $i++) {
    $diferente = ['documento' => synthetic_cpf(sprintf('%09d', random_int(0, 999999999)))] + $post;
    $r = $comCaptcha->receber($diferente, $origem, $eventosRecepcao);
    if ($r->protocolo !== null && $r->protocolo !== '' && !$r->jaAberto) {
        $aceitas++;
    }
}
T::same(3, $aceitas, 'failed captchas and invalid forms used up no slot: three reports still go through');

if (getenv('REDIS_HOST') !== false) {
    // And the fourth real report from that email is the one refused.
    $quarta = $comCaptcha->receber(['documento' => synthetic_cpf(sprintf('%09d', random_int(0, 999999999)))] + $post, $origem, $eventosRecepcao);
    T::ok('the fourth report from one email within the hour is told to wait', $quarta->espera !== null && $quarta->protocolo === null);
}

// --- Redis down: submissions still go through ---------------------------------------

$redisPortaAntes = $_ENV['REDIS_PORT'] ?? null;
$_ENV['REDIS_PORT'] = '1';
Limite::reset();

$recibos = 0;
$alertas = 0;
$recepcao = $montar(true, $recibos, $alertas);
$post     = $novoPost();
$semRedis = $recepcao->receber($post, $origemNova(), $eventosRecepcao);
T::ok('with Redis unreachable, a report is still accepted', $semRedis->protocolo !== null && $semRedis->protocolo !== '');
T::same(1, $linhas($post['documento']), 'and saved');

// Without the limit in the way, every repeated press reaches the duplicate
// check, which is now the only thing between the button and the database.
for ($i = 0; $i < 5; $i++) {
    $repetida = $recepcao->receber($post, $origemNova(), $eventosRecepcao);
    T::ok('press ' . ($i + 2) . ' without Redis: already open, same protocol', $repetida->jaAberto && $repetida->protocolo === $semRedis->protocolo);
}
T::same(1, $linhas($post['documento']), 'six presses without Redis: still one row');
T::same(1, $recibos, 'one receipt');
T::same(1, $alertas, 'one alert');

if ($redisPortaAntes === null) {
    unset($_ENV['REDIS_PORT']);
} else {
    $_ENV['REDIS_PORT'] = $redisPortaAntes;
}
Limite::reset();

$limparRecepcao();
