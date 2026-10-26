<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every judge-area page that changes something checks a CSRF token
 * (BAJAAPP-41).
 *
 * The HTTP tests (tests/csrf/http-test.sh) prove, form by form, that a
 * request without the token changes nothing. But they only cover the forms
 * someone remembered to list. This test is the safety net for the next page
 * somebody adds: it reads the source of every page in juiz/ and fails when
 *
 *   1. the page writes something (->save(, ->delete(, a file, a push
 *      notification...) and never calls one of the token checks; or
 *   2. the page shows a form with method="POST" and never prints a token
 *      field.
 *
 * It reads the code as text, so it cannot tell WHERE the check is, only that
 * it is there; where it is, before any change, is what the HTTP tests show.
 * A page that writes without a session token on purpose is listed in
 * EXEMPT with the reason. Adding a page there is a decision, made in review.
 */
final class CsrfCoverageTest extends TestCase
{
    /** Code that changes state: the database, files, or the outside world. */
    private const WRITES = '/->save\(|->delete\(|->refreshVarsAndPontos\(|file_put_contents\(|OneSignalClient::|curl_exec\(|->exec\(|beginTransaction\(|DeviceTokens::issue\(/';

    /** The token checks: Baja\Auth\Csrf, and the older names that call it. */
    private const CHECKS = '/Csrf::enforce\(|Csrf::postValido\(|Pagina::requireCsrfPost\(|Pagina::hasValidCsrfHeader\(/';

    /** What prints a token into a form. */
    private const FIELDS = '/Csrf::field\(|Csrf::campo\(|Pagina::csrfField\(|LoginCsrf::field\(|Pagina::confirmationForm\(/';

    /**
     * Pages that change something with no session token, and why that is
     * safe. Each reason says what authenticates the request instead: CSRF is
     * only possible against a credential the browser sends by itself (the
     * session cookie). A key in the request body is not one.
     */
    private const EXEMPT = [
        // docs/contagem.md: the lap counting phones and timing systems
        // authenticate every upload with a device token in the
        // Authorization header. The forum session is not even loaded.
        'contagem_sync.php' => 'device token in the Authorization header',
        // The one step of the count flow that uses the session: it hands a
        // logged-in phone its device token. A forged call creates a token
        // the attacker cannot read (no CORS), so it can do nothing with it.
        // Part of the lap counting flow, left as it is (BAJAAPP-41).
        'contagem_dispositivo.php' => 'issues a device token the caller alone can read; lap counting flow, left alone',
        // The spreadsheet's uploads: a shared key in the JSON body.
        'remote.php' => 'key in the JSON body ($_remoteKey)',
    ];

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        $pages = [];
        foreach (glob(__DIR__ . '/../../../juiz/*.php') as $file) {
            $pages[basename($file)] = [$file];
        }
        ksort($pages);
        return $pages;
    }

    #[DataProvider('pages')]
    public function testAPageThatWritesChecksTheToken(string $file): void
    {
        $code = $this->codeOf($file);
        $page = basename($file);

        if (!preg_match(self::WRITES, $code)) {
            $this->addToAssertionCount(1);  // changes nothing: no token needed
            return;
        }
        if (isset(self::EXEMPT[$page])) {
            $this->assertDoesNotMatchRegularExpression(self::CHECKS, $code,
                "$page is listed as exempt but checks a token: take it off the list");
            return;
        }

        $this->assertMatchesRegularExpression(self::CHECKS, $code,
            "$page changes something but never checks a CSRF token. Call Csrf::enforce() "
            . 'before the change (src/Baja/Auth/Csrf.php), or list the page in EXEMPT with the reason.');
    }

    #[DataProvider('pages')]
    public function testAPostFormCarriesAToken(string $file): void
    {
        $code = $this->codeOf($file);
        $page = basename($file);

        if (!preg_match('/method\s*=\s*[\'"]?post/i', $code)) {
            $this->addToAssertionCount(1);  // no POST form here
            return;
        }

        $this->assertMatchesRegularExpression(self::FIELDS, $code,
            "$page has a POST form but prints no token. Put <?= Csrf::field() ?> inside the form.");
    }

    /** The exempt list names real pages, so a renamed page cannot hide there. */
    public function testEveryExemptPageExists(): void
    {
        foreach (array_keys(self::EXEMPT) as $page) {
            $this->assertFileExists(__DIR__ . '/../../../juiz/' . $page);
        }
    }

    /** The page's code without comments, so a comment saying "->save(" counts for nothing. */
    private function codeOf(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }
}
