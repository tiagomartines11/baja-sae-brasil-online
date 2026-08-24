<?php

use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Requerimento\Formulario;
use Baja\Certificado\Requerimento\Limite;
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

if (getenv('REDIS_HOST') === false) {
    T::skip('submission limit', 'REDIS_HOST is not set');
} else {
    // Distinct per run, so a counter left over from an earlier run cannot
    // make this pass or fail spuriously.
    $origem = '203.0.113.' . random_int(1, 254);
    $email  = 'zz-fixture-' . random_int(0, 999999) . '@example.org';

    T::same(null, Limite::esperaSegundos($origem, $email), 'a fresh caller may submit');

    // The email ceiling is the lower of the two, and it is the one that
    // matters: every submission mails the address typed into the form, and
    // nothing proves it belongs to whoever is typing.
    for ($i = 0; $i < 3; $i++) {
        Limite::registrar($origem, $email);
    }

    T::ok('the fourth submission from one email waits', Limite::esperaSegundos($origem, $email) !== null);

    // And the wait must not follow the address to somebody else's mailbox, or
    // one abuser locks out a shared connection's worth of participants for an
    // hour. The address ceiling is higher precisely so that it is the email
    // that trips first.
    $outroEmail = 'zz-fixture-' . random_int(0, 999999) . '@example.org';
    T::same(null, Limite::esperaSegundos('', $outroEmail), 'another email is unaffected');
}
