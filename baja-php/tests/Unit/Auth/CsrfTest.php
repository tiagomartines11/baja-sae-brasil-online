<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Auth;

use Baja\Auth\Csrf;
use Baja\Certificado\Insercao\Csrf as CertificadoCsrf;
use PHPUnit\Framework\TestCase;

/**
 * The CSRF token of the judge and admin forms (BAJAAPP-41).
 *
 * The attack it stops: a page on another site holds a hidden form aimed at,
 * say, "delete this prova", and submits it. The admin's browser sends the
 * admin's session cookie along, so without a token the server cannot tell
 * the forged request from a real one.
 *
 * The token is computed from the session id (the forum's "_sid" cookie),
 * which the other site cannot read. So the other site cannot compute it
 * either, and its forged form arrives with no token or a wrong one.
 *
 * These tests fake the session by setting the cookie in $_COOKIE, the way PHP
 * would fill it from the request, and fake the form by setting $_POST. Each
 * test reads as: "with this session and this submitted value, the answer is
 * this". The full round trip through nginx, with real forms, is in
 * tests/csrf/http-test.sh.
 */
final class CsrfTest extends TestCase
{
    private const PREFIX = 'phpbb3_test';
    private const SESSION_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SESSION_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string|false $previousPrefix;

    protected function setUp(): void
    {
        // Which cookie holds the session id depends on the forum's cookie
        // prefix, read from the environment like the real site does.
        $this->previousPrefix = getenv('PHPBB_COOKIE_PREFIX');
        putenv('PHPBB_COOKIE_PREFIX=' . self::PREFIX);
        $_COOKIE = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        putenv($this->previousPrefix === false ? 'PHPBB_COOKIE_PREFIX' : 'PHPBB_COOKIE_PREFIX=' . $this->previousPrefix);
        $_COOKIE = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    private function loggedInAs(string $sessionId): void
    {
        $_COOKIE[self::PREFIX . '_sid'] = $sessionId;
    }

    // --- The token itself ---------------------------------------------------------

    /** A logged-in session gets a token: 64 hexadecimal characters (SHA-256). */
    public function testASessionHasAToken(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Csrf::token());
    }

    /**
     * The same session always gets the same token, so a form left open in a
     * second tab, or all afternoon, still saves.
     */
    public function testTheTokenIsStableWithinASession(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertSame(Csrf::token(), Csrf::token());
    }

    /** The token does not give the session id away: it is not in it. */
    public function testTheTokenDoesNotContainTheSessionId(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertStringNotContainsString(self::SESSION_A, Csrf::token());
    }

    /**
     * Without a session there is no token at all, rather than one anybody
     * could predict. Nothing can be valid then.
     */
    public function testNoSessionNoToken(): void
    {
        $this->assertSame('', Csrf::token());
        $this->assertFalse(Csrf::isValid(Csrf::DEFAULT_FORM, ''));
    }

    // --- Checking a submitted token -----------------------------------------------

    /** Valid: the token this session's form carried. */
    public function testTheRightTokenIsValid(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $_POST[Csrf::FIELD] = Csrf::token();

        $this->assertTrue(Csrf::requestIsValid());
    }

    /** Missing: a forged form that does not know there is a token. */
    public function testAMissingTokenIsRefused(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertFalse(Csrf::requestIsValid());
    }

    /** Empty: the field is there but has no value. */
    public function testAnEmptyTokenIsRefused(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $_POST[Csrf::FIELD] = '';

        $this->assertFalse(Csrf::requestIsValid());
    }

    /** Wrong: a guess, or the token changed by one character. */
    public function testAWrongTokenIsRefused(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $token = Csrf::token();
        $_POST[Csrf::FIELD] = substr($token, 0, -1) . ($token[-1] === '0' ? '1' : '0');

        $this->assertFalse(Csrf::requestIsValid());
    }

    /**
     * From another session: an attacker who logs in themselves gets a real
     * token, but a token of THEIR session. Planted in a form submitted by
     * someone else's browser, it is refused.
     */
    public function testATokenFromAnotherSessionIsRefused(): void
    {
        $this->loggedInAs(self::SESSION_B);
        $tokenOfB = Csrf::token();

        $this->loggedInAs(self::SESSION_A);
        $_POST[Csrf::FIELD] = $tokenOfB;

        $this->assertFalse(Csrf::requestIsValid());
    }

    /**
     * "Expiry": there is no time limit, the token lasts as long as the
     * session. Logging out and in again gives a new session id, and the old
     * token stops working.
     */
    public function testATokenStopsWorkingWhenTheSessionEnds(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $old = Csrf::token();

        $this->loggedInAs(self::SESSION_B);  // logged in again: a new session id

        $this->assertFalse(Csrf::isValid(Csrf::DEFAULT_FORM, $old));
    }

    /**
     * A field sent as a list (_csrf[]=x) is refused quietly, not with a PHP
     * warning about converting an array to text.
     */
    public function testATokenSentAsAListIsRefused(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $_POST[Csrf::FIELD] = [Csrf::token()];

        $this->assertFalse(Csrf::requestIsValid());
    }

    /** A token for one form name is not valid for another. */
    public function testATokenIsTiedToItsFormName(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertFalse(Csrf::isValid('outro_formulario', Csrf::token()));
    }

    // --- From JavaScript ------------------------------------------------------------

    /**
     * A script sends the token in the X-CSRF-Token header instead of a form
     * field (PHP shows that header as HTTP_X_CSRF_TOKEN).
     */
    public function testTheTokenMayComeInAHeader(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();

        $this->assertTrue(Csrf::requestIsValid());
    }

    /** A wrong header is refused like a wrong field. */
    public function testAWrongHeaderIsRefused(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('0', 64);

        $this->assertFalse(Csrf::requestIsValid());
    }

    /**
     * A token in the URL (?_csrf=...) is NOT read. A token in a link would
     * end up in browser history and server logs, and a GET must never change
     * anything anyway.
     */
    public function testATokenInTheQueryStringIsNotRead(): void
    {
        $this->loggedInAs(self::SESSION_A);
        $_GET[Csrf::FIELD] = Csrf::token();

        try {
            $this->assertFalse(Csrf::requestIsValid());
        } finally {
            unset($_GET[Csrf::FIELD]);
        }
    }

    // --- What the pages print and answer ------------------------------------------

    /** The hidden input that goes in every form: the field name and the token. */
    public function testTheHiddenField(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertSame(
            '<input type="hidden" name="_csrf" value="' . Csrf::token() . '">',
            Csrf::field()
        );
    }

    /**
     * The refusal: status 403 ("forbidden"), and a message saying that
     * nothing was changed, as a page for a form and as JSON for a script.
     */
    public function testTheRefusalSaysNothingChanged(): void
    {
        [$status, $type, $body] = Csrf::refusal(false);
        $this->assertSame(403, $status);
        $this->assertStringStartsWith('text/html', $type);
        $this->assertStringContainsString('Nada foi alterado', $body);

        [$status, $type, $body] = Csrf::refusal(true);
        $this->assertSame(403, $status);
        $this->assertStringStartsWith('application/json', $type);
        $json = json_decode($body, true);
        $this->assertFalse($json['ok']);
        $this->assertSame('csrf', $json['erro']);
    }

    // --- The certificate pages ---------------------------------------------------

    /**
     * The certificate and lap-count review pages use the older class name,
     * which now passes everything on to this one. Their tokens must be
     * exactly what they were, or a form open during the deploy would fail.
     * Before BAJAAPP-41 a token was hash_hmac('sha256', form name, session
     * id); this pins that.
     */
    public function testTheCertificatePagesKeepTheirTokens(): void
    {
        $this->loggedInAs(self::SESSION_A);

        $this->assertSame(hash_hmac('sha256', 'lote', self::SESSION_A), CertificadoCsrf::token('lote'));
        $this->assertSame(CertificadoCsrf::campo('lote'), Csrf::field('lote'));

        $_POST['_csrf'] = hash_hmac('sha256', 'lote', self::SESSION_A);
        $this->assertTrue(CertificadoCsrf::postValido('lote'));
        $this->assertFalse(CertificadoCsrf::postValido('outro'));
    }
}
