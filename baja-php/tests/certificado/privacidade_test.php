<?php

use Baja\Certificado\Config;
use Baja\Certificado\Requerimento\Aviso;
use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Template;
use Baja\Model\CertificadoRequerimento;

/*
 * The Aviso de Privacidade link.
 *
 * Its address comes from the PRIVACY_NOTICE_URL environment variable, through
 * Config::privacyNoticeUrl(), and it is printed in three places: the footer of
 * every certificate page, the /requerimento form, and the confirmation email a
 * requerimento sends. The rule is small. An absolute https:// URL is linked in
 * all three; anything else — the variable missing, empty, or holding something
 * that is not such a URL — means no link and no mention of a notice anywhere.
 *
 * Every assertion below looks for the words "Aviso de Privacidade", because
 * that is the text of the link in the HTML and the label of the line in the
 * email. Where the words are, the link is; where they are not, nothing about
 * the notice was printed.
 */

T::group('privacidade');

// What the process started with, so it can be put back at the end: the files
// that run after this one expect the environment the container was given.
$privacidadeEnvOriginal    = $_ENV['PRIVACY_NOTICE_URL'] ?? null;
$privacidadeGetenvOriginal = getenv('PRIVACY_NOTICE_URL');

/**
 * Set PRIVACY_NOTICE_URL to $valor for the rest of this file, or remove it
 * entirely when $valor is null.
 *
 * Two things are written because Baja\Util\Env::get() reads two things: $_ENV,
 * an array PHP fills once when the process starts, and getenv(), which reads
 * the live process environment that putenv() changes. Changing only one of
 * them is how a test ends up asserting against whatever the container was
 * started with instead of the value it meant to set.
 */
$definirAviso = static function (?string $valor): void {
    if ($valor === null) {
        unset($_ENV['PRIVACY_NOTICE_URL']);
        putenv('PRIVACY_NOTICE_URL');

        return;
    }

    $_ENV['PRIVACY_NOTICE_URL'] = $valor;
    putenv('PRIVACY_NOTICE_URL=' . $valor);
};

/**
 * The footer of a certificate page, as a browser would receive it.
 *
 * Template::printFooter() echoes its HTML rather than returning it, so the
 * output is caught in a buffer: everything echoed between ob_start() and
 * ob_get_clean() ends up in the returned string instead of on the screen.
 */
$rodape = static function (): string {
    ob_start();
    Template::printFooter();

    return (string) ob_get_clean();
};

/**
 * A requerimento for the confirmation email to be about.
 *
 * Built in memory and never saved. The email needs a protocol, a case, an
 * event, a document and a date, and none of them needs a row in the database
 * to exist. The document is synthetic, as every fixture in this suite is.
 */
$requerimento = new CertificadoRequerimento();
$requerimento->setRequerimentoId(str_repeat('A', 22));
$requerimento->setCaso(Caso::AUSENTE);
$requerimento->setEventoTexto('Baja SAE Sudeste, 2009');
$requerimento->setDocumento(synthetic_cpf('123456789'));
$requerimento->setEmail('fulano@example.org');
$requerimento->setCriadoEm(new DateTime('2026-10-20 10:00:00'));

/**
 * The /requerimento page, rendered the way the web server renders it, under
 * an environment this test chooses.
 *
 * The page is a script that prints HTML, written to run once per request
 * under php-fpm. The closest thing to that here is a fresh `php` process of
 * its own. It gets the environment handed to it and nothing else, which is
 * what makes "the variable is not set at all" a case this test can really
 * produce: this process cannot forget the copy of the environment that $_ENV
 * took when it started, but a new process never had it.
 *
 * nginx prepends src/bootstrap.php to every page it serves (see
 * baja-infra/nginx/templates/baja-app-certificado.conf.template), so the
 * same is done here with -d auto_prepend_file. SKIP_AUTH is what that vhost
 * sets too: the certificate pages have no forum session.
 *
 * Needs the database, because the form lists the events to choose from.
 *
 * @param string|null $aviso the value of PRIVACY_NOTICE_URL, or null for unset
 */
$formulario = static function (?string $aviso): string {
    $raiz = dirname(__DIR__, 2);

    $ambiente = getenv();
    unset($ambiente['PRIVACY_NOTICE_URL']);
    if ($aviso !== null) {
        $ambiente['PRIVACY_NOTICE_URL'] = $aviso;
    }
    $ambiente['SKIP_AUTH'] = '1';

    $processo = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=' . $raiz . '/src/bootstrap.php', $raiz . '/certificado/requerimento.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $raiz,
        $ambiente
    );

    if (!is_resource($processo)) {
        throw new RuntimeException('could not start a php process for requerimento.php');
    }

    $html  = (string) stream_get_contents($pipes[1]);
    $erros = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $saida = proc_close($processo);

    if ($saida !== 0) {
        throw new RuntimeException("requerimento.php exited with status {$saida}: {$erros}");
    }

    return $html;
};

$comFormulario = test_db_available();
if (!$comFormulario) {
    T::skip('the /requerimento form', 'BAJA_TEST_DB is not 1');
}

// --- not set, or set to nothing: no link anywhere ----------------------------

foreach (['unset' => null, 'empty' => ''] as $rotulo => $valor) {
    $definirAviso($valor);

    T::same('', Config::privacyNoticeUrl(), "{$rotulo} reads as no URL");

    $html = $rodape();
    T::ok("{$rotulo}: the footer still offers the requerimento", str_contains($html, 'href="/requerimento"'));
    T::ok("{$rotulo}: the footer does not mention a privacy notice", !str_contains($html, 'Aviso de Privacidade'));

    $texto = Aviso::textoDoRecibo($requerimento);
    T::ok("{$rotulo}: the email still carries the protocol", str_contains($texto, 'Protocolo: ' . str_repeat('A', 22)));
    T::ok("{$rotulo}: the email does not mention a privacy notice", !str_contains($texto, 'Aviso de Privacidade'));

    if ($comFormulario) {
        T::ok("{$rotulo}: the form does not mention a privacy notice", !str_contains($formulario($valor), 'Aviso de Privacidade'));
    }
}

// --- an absolute https:// URL: linked in all three places --------------------
//
// The URL carries an ampersand and an apostrophe on purpose. In HTML both have
// to be escaped (&amp; and &#039;) or the attribute ends early, so finding
// them escaped in the output is how the test tells a properly escaped link
// from a raw one. The email is plain text, so there the URL must appear
// exactly as given.

$url      = "https://example.org/aviso?a=1&b=2'c";
$hrefHtml = 'href="https://example.org/aviso?a=1&amp;b=2&#039;c"';

$definirAviso($url);
T::same($url, Config::privacyNoticeUrl(), 'an https:// URL is accepted as given');

$html = $rodape();
T::ok('the footer links the notice', str_contains($html, 'Aviso de Privacidade'));
T::ok('the footer escapes the URL for HTML', str_contains($html, $hrefHtml), $html);
T::ok('the footer does not print the raw URL', !str_contains($html, $url));

$texto = Aviso::textoDoRecibo($requerimento);
T::ok('the email names the notice with the plain URL', str_contains($texto, 'Aviso de Privacidade: ' . $url), $texto);
T::ok('the email does not escape it like HTML', !str_contains($texto, '&amp;'));

if ($comFormulario) {
    $html = $formulario($url);
    T::ok('the form links the notice', str_contains($html, 'Aviso de Privacidade'));
    T::ok('the form escapes the URL for HTML', str_contains($html, $hrefHtml));
    T::ok('the form does not print the raw URL', !str_contains($html, $url));
}

// --- anything else: treated as empty, and said once --------------------------
//
// Each of these is a URL in some sense, and each would be wrong in an href:
// http:// is a privacy notice served over a connection anyone on the path can
// rewrite, javascript: is script running on the certificate pages, and a
// relative path is a link into this site that goes nowhere.
//
// The rejection is logged, so that somebody can find the typo. error_log()
// writes wherever the error_log setting points, so it is pointed at a file
// for the duration: that is the only way to read back what it wrote.

$invalidos = [
    'an http:// URL'         => 'http://example.org/aviso',
    'a javascript: URL'      => 'javascript:alert(1)',
    'a relative path'        => '/aviso-de-privacidade',
    'a URL without a scheme' => 'example.org/aviso',
];

$arquivoDeLog = tempnam(sys_get_temp_dir(), 'privacidade');
ini_set('error_log', $arquivoDeLog);

foreach ($invalidos as $rotulo => $valor) {
    $definirAviso($valor);

    T::same('', Config::privacyNoticeUrl(), "{$rotulo} reads as no URL");
    T::ok("{$rotulo}: the footer does not link it", !str_contains($rodape(), 'Aviso de Privacidade'));
    T::ok("{$rotulo}: the email does not mention it", !str_contains(Aviso::textoDoRecibo($requerimento), 'Aviso de Privacidade'));

    if ($comFormulario) {
        T::ok("{$rotulo}: the form does not link it", !str_contains($formulario($valor), 'Aviso de Privacidade'));
    }
}

ini_restore('error_log');

$linhasDeLog = array_filter(
    explode("\n", (string) file_get_contents($arquivoDeLog)),
    static fn (string $linha): bool => str_contains($linha, 'PRIVACY_NOTICE_URL')
);
unlink($arquivoDeLog);

// Four bad values, each read several times, and one line: the log says it
// once per process, not once per page view.
T::ok('a rejected value is logged once', count($linhasDeLog) === 1, implode("\n", $linhasDeLog));

// --- put the environment back for the files that run after this one ----------

if ($privacidadeGetenvOriginal === false) {
    putenv('PRIVACY_NOTICE_URL');
} else {
    putenv('PRIVACY_NOTICE_URL=' . $privacidadeGetenvOriginal);
}

if ($privacidadeEnvOriginal === null) {
    unset($_ENV['PRIVACY_NOTICE_URL']);
} else {
    $_ENV['PRIVACY_NOTICE_URL'] = $privacidadeEnvOriginal;
}
