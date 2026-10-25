<?php
/*
 * Who is emailed when a participant reports a problem with a certificate.
 *
 * The rule lives in Destinatarios::escolher(). It takes two inputs:
 *
 *   1. the text of the CERT_REPORT_EMAILS variable, e.g. "a@x.org, b@x.org";
 *   2. a function that, when called, returns the "derived list": the forum
 *      addresses of every staff user with the `certificados` or `admin`
 *      permission.
 *
 * In production the second input reads a database and the forum. Here it is
 * replaced by a small fake function that returns a fixed list and remembers
 * whether it was called, so these tests need no database, no forum, and no
 * real environment variable. Nothing below touches the network or a table.
 *
 * The rule being tested:
 *
 *   - the variable has at least one valid address -> only those addresses;
 *     the derived list is not even looked at;
 *   - the variable is blank                        -> the derived list;
 *   - the variable is set but nothing in it is a   -> the derived list, and an
 *     valid address (a typo)                          error goes to the log.
 */

use Baja\Certificado\Requerimento\Destinatarios;

T::group('requerimento/destinatarios');

/*
 * Run the rule once and report what happened.
 *
 * $variavel      what CERT_REPORT_EMAILS would contain.
 * $listaDerivada what the fake permission lookup returns if it is asked.
 *
 * Returns three things:
 *   'enderecos'  the addresses the rule chose to notify;
 *   'consultou'  true if the rule asked for the derived list at all;
 *   'log'        every line the rule wrote with error_log().
 *
 * About the log: PHP's error_log() writes to whatever file the `error_log`
 * setting names. To read back what was written, the setting is pointed at a
 * fresh temporary file just for this call, then put back as it was.
 */
$executar = static function (string $variavel, array $listaDerivada): array {
    $consultou = false;

    // The fake. `use (&$consultou)` lets it flip the flag above when called.
    $fakeDaEquipe = static function () use ($listaDerivada, &$consultou): array {
        $consultou = true;

        return $listaDerivada;
    };

    $arquivoDeLog = tempnam(sys_get_temp_dir(), 'destinatarios');
    ini_set('error_log', $arquivoDeLog);

    try {
        $enderecos = Destinatarios::escolher($variavel, $fakeDaEquipe);
    } finally {
        ini_restore('error_log');
    }

    $log = array_values(array_filter(explode("\n", (string) file_get_contents($arquivoDeLog))));
    unlink($arquivoDeLog);

    return ['enderecos' => $enderecos, 'consultou' => $consultou, 'log' => $log];
};

/* True when any captured log line contains $trecho. */
$logContem = static function (array $log, string $trecho): bool {
    foreach ($log as $linha) {
        if (str_contains($linha, $trecho)) {
            return true;
        }
    }

    return false;
};

// Two staff members who hold the permission. Used as the derived list
// whenever a test needs "admins exist".
$admins = ['admin.um@example.org', 'admin.dois@example.org'];

// --- 1. The variable is set: only its addresses ------------------------------
//
// Even though two admins exist, neither is emailed. And the fake was never
// called, which is how we know the real code would not have queried the
// database or the forum either.

$r = $executar('caixa.compartilhada@example.org, outra@example.org', $admins);

T::same(
    ['caixa.compartilhada@example.org', 'outra@example.org'],
    $r['enderecos'],
    'set: only the addresses in the variable are notified'
);
T::ok('set: no admin is added', array_intersect($admins, $r['enderecos']) === []);
T::ok('set: the derived list is never consulted', $r['consultou'] === false);
T::same([], $r['log'], 'set: nothing is logged');

// A valid address next to a broken one still counts as "set": the valid one
// is used on its own, exactly as the old code dropped invalid entries.
$r = $executar('nao-e-um-email, caixa@example.org', $admins);
T::same(['caixa@example.org'], $r['enderecos'], 'set with one bad entry: the valid one alone is notified');
T::ok('set with one bad entry: the derived list is never consulted', $r['consultou'] === false);

// --- 2. The variable is blank: the derived list, unchanged -------------------

$r = $executar('', $admins);

T::same($admins, $r['enderecos'], 'blank: the derived list is notified, in order');
T::ok('blank: the derived list is consulted', $r['consultou'] === true);
T::same([], $r['log'], 'blank: nothing is logged');

// Whitespace only is what a .env line like `CERT_REPORT_EMAILS=   ` gives.
// That is still blank, not a misconfiguration.
$r = $executar('   ', $admins);
T::same($admins, $r['enderecos'], 'whitespace only counts as blank');
T::same([], $r['log'], 'whitespace only logs nothing');

// --- 3. Duplicates and different capitals in the variable --------------------
//
// "Caixa@Example.org" and "caixa@example.org" are the same mailbox, so it
// must be emailed once, not twice. When the same address appears more than
// once, the spelling of the LAST one is kept (that is how dedup already
// worked; this test pins it).

$r = $executar('caixa@example.org, Caixa@Example.org, outra@example.org, caixa@example.org', $admins);

T::same(
    ['caixa@example.org', 'outra@example.org'],
    $r['enderecos'],
    'duplicates in the variable are emailed once, ignoring case'
);

// --- 4. Set, but every entry is invalid --------------------------------------
//
// Probably a typo. Emailing nobody would be the worst outcome — the
// participant has already been told their report was received — so the rule
// falls back to the derived list and logs an error so someone fixes it.

$r = $executar('nao-e-um-email, tambem nao', $admins);

T::same($admins, $r['enderecos'], 'all invalid: falls back to the derived list');
T::ok('all invalid: the derived list is consulted', $r['consultou'] === true);
T::ok(
    'all invalid: an error naming the variable is logged',
    $logContem($r['log'], 'CERT_REPORT_EMAILS is set but none of its')
);
T::ok(
    'all invalid: the bad values themselves are not written to the log',
    !$logContem($r['log'], 'nao-e-um-email')
);

// --- 5. Nobody at all --------------------------------------------------------
//
// The variable is blank and no staff member has a usable address. There is
// no one to email; the result is empty and the long-standing "nobody to
// notify" error is logged. (The report itself is still saved elsewhere.)

$r = $executar('', []);

T::same([], $r['enderecos'], 'blank and no derived addresses: nobody is notified');
T::ok('blank and no derived addresses: "nobody to notify" is logged', $logContem($r['log'], 'nobody to notify'));
// The message says WHY nobody was found, so the reader knows what to fix:
// here, that the variable is blank (not that it is wrong).
T::ok('blank and no derived addresses: the log says the variable is blank', $logContem($r['log'], 'CERT_REPORT_EMAILS is blank'));

// Same, but the variable was set to garbage: both errors appear, the
// misconfiguration first, then "nobody to notify".
$r = $executar('nao-e-um-email', []);

T::same([], $r['enderecos'], 'invalid variable and no derived addresses: nobody is notified');
T::ok('invalid variable and no derived addresses: the misconfiguration is logged', $logContem($r['log'], 'CERT_REPORT_EMAILS is set but none of its'));
T::ok('invalid variable and no derived addresses: "nobody to notify" is logged', $logContem($r['log'], 'nobody to notify'));
// Here the variable WAS set, so the log must not claim it is blank.
T::ok('invalid variable and no derived addresses: the log says the variable has no valid address', $logContem($r['log'], 'CERT_REPORT_EMAILS has no valid address'));
T::ok('invalid variable and no derived addresses: the log does not call the variable blank', !$logContem($r['log'], 'CERT_REPORT_EMAILS is blank'));

// The derived list is validated and deduplicated the same way. A forum
// profile with a broken address is skipped, and an address that appears
// twice (two accounts sharing an inbox) is emailed once.
$r = $executar('', ['nao-e-um-email', 'admin.um@example.org', 'ADMIN.UM@example.org']);
T::same(['ADMIN.UM@example.org'], $r['enderecos'], 'the derived list is validated and deduplicated too');
