<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Auth;

use Baja\Auth\StaffCookie;
use Baja\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Who gets the baja_staff cookie (docs/results-caching.md).
 *
 * The cookie makes a browser skip the results cache, so that a judge sees
 * the live page. It must go to judges and to nobody else: team members log
 * in to the same forum, and if they got it, the cache would be skipped for
 * thousands of visitors.
 *
 * On every page that has a forum session, StaffCookie::decide() looks at
 * three things and answers KEEP (send nothing), SET (send the cookie) or
 * CLEAR (delete it from the browser):
 *
 *   - is the logged-in user a judge? (StaffCookie::isStaff)
 *   - what cookie did the browser send? (null when none)
 *   - what is the right value? (STAFF_COOKIE_VALUE in .env; '' when unset)
 */
final class StaffCookieTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';

    // --- Judges ---------------------------------------------------------------

    /** The page a judge lands on after logging in sets the cookie. */
    public function testAJudgeWithoutTheCookieGetsIt(): void
    {
        $this->assertSame(StaffCookie::SET, StaffCookie::decide(true, null, self::SECRET));
    }

    /** Already right: nothing to send, so no Set-Cookie on every page. */
    public function testAJudgeWithTheRightCookieKeepsIt(): void
    {
        $this->assertSame(StaffCookie::KEEP, StaffCookie::decide(true, self::SECRET, self::SECRET));
    }

    /** After the value is rotated in .env, the judge's next page fixes it. */
    public function testAJudgeWithAnOldValueGetsTheNewOne(): void
    {
        $this->assertSame(StaffCookie::SET, StaffCookie::decide(true, 'an-old-value', self::SECRET));
    }

    /** No value in .env: the feature is off, and nobody gets a cookie. */
    public function testWithoutASecretNobodyGetsIt(): void
    {
        $this->assertSame(StaffCookie::KEEP, StaffCookie::decide(true, null, ''));
        $this->assertSame(StaffCookie::CLEAR, StaffCookie::decide(true, 'left-over', ''));
    }

    // --- Everybody else -------------------------------------------------------

    public function testSomebodyWhoIsNotAJudgeDoesNotGetIt(): void
    {
        $this->assertSame(StaffCookie::KEEP, StaffCookie::decide(false, null, self::SECRET));
    }

    /**
     * Somebody who is not (or no longer) a judge but has the cookie: a former
     * judge, a judge whose session ended, a copied cookie. Deleted.
     */
    public function testSomebodyWhoIsNotAJudgeLosesIt(): void
    {
        $this->assertSame(StaffCookie::CLEAR, StaffCookie::decide(false, self::SECRET, self::SECRET));
        $this->assertSame(StaffCookie::CLEAR, StaffCookie::decide(false, 'anything', self::SECRET));
    }

    // --- Who is a judge -------------------------------------------------------
    //
    // Since BAJAAPP-76 "staff" means "holds an active RBAC grant somewhere"
    // (Authorization::isStaff), which needs the grants table. The judge, the
    // admin, the team member with only a queue code, and the expired and
    // revoked grants are tested against MySQL in
    // tests/Rbac/Db/CallSiteParityTest.php. Here, only the cases that need
    // no database.

    /** No row here at all: a forum user this system does not know. */
    public function testAForumUserWithNoAccountHereIsNotStaff(): void
    {
        $this->assertFalse(StaffCookie::isStaff(null));
    }

    /**
     * A row that was never saved has no id, so it cannot hold a grant:
     * not staff, whatever its old permission list says.
     */
    public function testAnUnsavedUserIsNotStaff(): void
    {
        $this->assertFalse(StaffCookie::isStaff(self::user(['index', 'admin'])));
    }

    // --- The value from .env --------------------------------------------------

    protected function tearDown(): void
    {
        putenv('STAFF_COOKIE_VALUE');
    }

    public function testAValueFromOpensslIsUsed(): void
    {
        putenv('STAFF_COOKIE_VALUE=' . self::SECRET);
        $this->assertSame(self::SECRET, StaffCookie::secret());
    }

    /**
     * The value is also written into nginx's config and a Cloudflare rule.
     * A short one could be guessed, and a quote or a space could break
     * either syntax. Such a value counts as unset: nobody gets a cookie.
     */
    public function testAShortOrOddValueIsIgnored(): void
    {
        foreach (['', 'short', str_repeat('a', 31), str_repeat('a', 40) . '"', str_repeat('a', 40) . ' x'] as $bad) {
            putenv('STAFF_COOKIE_VALUE=' . $bad);
            $this->assertSame('', StaffCookie::secret(), "value: [$bad]");
        }
    }

    /** @param string[] $permissions */
    private static function user(array $permissions): User
    {
        // An unsaved model object: no database involved.
        return (new User())->setPermissions($permissions);
    }
}
