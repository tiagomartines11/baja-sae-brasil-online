<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Expiry\GrantExpiry;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Tests\Support\FixedClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * When a new grant ends (BAJAAPP-12), without a database.
 *
 * THE RULES
 *
 * A role given on an event, or on one of its provas or queues, is for that
 * event. Unless the granter says otherwise, it ends DAYS days after the
 * event's last day (evento.data_fim), at 23:59:59. DAYS is configurable;
 * 7 here.
 *
 * If the event has no data_fim, the grant is refused unless the granter
 * gives an end themselves: the system does not guess when access to an
 * event should stop.
 *
 * A grant never outlives the granter's own right to grant there. Asking
 * for a later end is refused, with a message saying until when they may
 * grant; it is not quietly shortened. The default end, which nobody asked
 * for, is brought forward to that limit instead.
 *
 * A grant can never be made already ended. Grants above an event (a
 * program, the platform) have no default end of their own.
 *
 * Here "now" is 1 March 2099 and the events are made up: 96BR ends on
 * 12 March 2099, 96SE has no end date yet.
 */
final class GrantExpiryTest extends TestCase
{
    /** No limit from the granter: what a platform admin with a grant that never ends gives. */
    private const UNLIMITED = null;

    private function expiry(int $days = 7): GrantExpiry
    {
        $lastDays = ['96BR' => '2099-03-12', '96SE' => null];
        return new GrantExpiry(
            fn(string $eventoId) => $lastDays[$eventoId] ?? null,
            new FixedClock('2099-03-01 10:00:00'),
            $days
        );
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }

    /** A judge's role on a prova ends 7 days after the event's last day, at the end of that day. */
    public function testAGrantInsideAnEventEndsDaysAfterIt(): void
    {
        $validUntil = $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), null, self::UNLIMITED);

        $this->assertSame('2099-03-19 23:59:59', $validUntil?->format('Y-m-d H:i:s'));
    }

    /** The same for the event itself and for its queues. */
    public function testTheSameForTheEventAndItsQueues(): void
    {
        $this->assertSame('2099-03-19', $this->expiry()->validUntilFor(ScopeNode::evento('96BR'), null, self::UNLIMITED)?->format('Y-m-d'));
        $this->assertSame('2099-03-19', $this->expiry()->validUntilFor(ScopeNode::fila('96BR', 1), null, self::UNLIMITED)?->format('Y-m-d'));
    }

    /** The number of days is a setting. */
    public function testTheNumberOfDaysIsConfigurable(): void
    {
        $this->assertSame('2099-03-12', $this->expiry(0)->validUntilFor(ScopeNode::evento('96BR'), null, self::UNLIMITED)?->format('Y-m-d'));
        $this->assertSame('2099-04-11', $this->expiry(30)->validUntilFor(ScopeNode::evento('96BR'), null, self::UNLIMITED)?->format('Y-m-d'));
    }

    /** An end the granter gives is kept as it is. */
    public function testAnExplicitEndIsKept(): void
    {
        $explicit = self::at('2099-03-11 18:00:00');

        $this->assertEquals($explicit, $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), $explicit, self::UNLIMITED));
    }

    /** No data_fim and no explicit end: refused, not guessed. */
    public function testAnEventWithoutAnEndDateNeedsAnExplicitEnd(): void
    {
        try {
            $this->expiry()->validUntilFor(ScopeNode::prova('96SE', 'ZZA'), null, self::UNLIMITED);
            $this->fail('A grant on an event with no end date was given an end by guessing.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::VALID_UNTIL_REQUIRED, $e->reason);
        }
    }

    /**
     * Even when the granter's own limit is known: that limit is when THEY
     * stop, not when this event ends, so it is no answer to "when should
     * access to this event stop?".
     */
    public function testAnEventWithoutAnEndDateNeedsAnExplicitEndEvenWithAGranterLimit(): void
    {
        $this->expectException(GrantRefused::class);
        $this->expiry()->validUntilFor(ScopeNode::evento('96SE'), null, self::at('2099-05-01 00:00:00'));
    }

    /** With an explicit end, the same grant is fine. */
    public function testAnEventWithoutAnEndDateIsFineWithAnExplicitEnd(): void
    {
        $explicit = self::at('2099-06-01 00:00:00');

        $this->assertEquals($explicit, $this->expiry()->validUntilFor(ScopeNode::evento('96SE'), $explicit, self::UNLIMITED));
    }

    /** An end in the past, or exactly now, is refused: the grant would be born ended. */
    public function testAnEndThatIsNotInTheFutureIsRefused(): void
    {
        foreach (['2099-02-28 00:00:00', '2099-03-01 10:00:00'] as $past) {
            try {
                $this->expiry()->validUntilFor(ScopeNode::evento('96BR'), self::at($past), self::UNLIMITED);
                $this->fail("valid_until $past was accepted.");
            } catch (GrantRefused $e) {
                $this->assertSame(GrantRefused::VALID_UNTIL_IN_PAST, $e->reason);
            }
        }
    }

    /** A grant on an event long over would default to an end already gone: refused too. */
    public function testADefaultEndAlreadyGoneIsRefused(): void
    {
        $expiry = new GrantExpiry(fn() => '2022-04-24', new FixedClock('2099-03-01 10:00:00'));

        $this->expectException(GrantRefused::class);
        $expiry->validUntilFor(ScopeNode::evento('22BR'), null, self::UNLIMITED);
    }

    /** Above an event there is no default end: a program manager's role lasts until revoked. */
    public function testGrantsAboveAnEventHaveNoDefaultEnd(): void
    {
        $this->assertNull($this->expiry()->validUntilFor(ScopeNode::programa('BAJA'), null, self::UNLIMITED));
        $this->assertNull($this->expiry()->validUntilFor(ScopeNode::platform(), null, self::UNLIMITED));
    }

    // ------------------------------------------------------------------
    // The granter's own limit
    // ------------------------------------------------------------------

    /**
     * SITUATION: a head judge whose own role ends on 5 March asks to make a
     * judge until 1 April.
     *
     * WHY IT MATTERS: otherwise anyone with a short-lived right to grant
     * could hand out long-lived access, and keep it alive through a friend
     * after their own ended. EXPECTED: refused, and the message says until
     * when they may grant, so they can ask again with a date that works.
     */
    public function testAnExplicitEndAfterTheGranterLimitIsRefused(): void
    {
        try {
            $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), self::at('2099-04-01 00:00:00'), self::at('2099-03-05 18:00:00'));
            $this->fail('A grant was allowed to outlive the right of the person who made it.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::VALID_UNTIL_AFTER_GRANTER, $e->reason);
            $this->assertStringContainsString('2099-03-05 18:00:00', $e->getMessage(), 'The message says until when the granter may grant.');
        }
    }

    /** Ending exactly when the granter's right ends is allowed: it does not outlive it. */
    public function testAnExplicitEndExactlyAtTheGranterLimitIsKept(): void
    {
        $limit = self::at('2099-03-05 18:00:00');

        $this->assertEquals($limit, $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), $limit, $limit));
    }

    /** Earlier than the limit is, of course, fine. */
    public function testAnExplicitEndBeforeTheGranterLimitIsKept(): void
    {
        $explicit = self::at('2099-03-04 12:00:00');

        $this->assertEquals($explicit, $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), $explicit, self::at('2099-03-05 18:00:00')));
    }

    /**
     * The default (19 March) would outlive a granter whose right ends on
     * 5 March: it is brought forward to 5 March. Nobody typed 19 March, so
     * nothing they asked for is being changed.
     */
    public function testTheDefaultEndIsBroughtForwardToTheGranterLimit(): void
    {
        $limit = self::at('2099-03-05 18:00:00');

        $this->assertEquals($limit, $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), null, $limit));
    }

    /** A granter whose right lasts longer than the default does not lengthen it. */
    public function testALaterGranterLimitLeavesTheDefaultAlone(): void
    {
        $validUntil = $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), null, self::at('2099-12-31 00:00:00'));

        $this->assertSame('2099-03-19 23:59:59', $validUntil?->format('Y-m-d H:i:s'));
    }

    /**
     * On a program there is no default end. A grant made there by someone
     * whose own right ends must still end then, not last forever.
     */
    public function testAGrantWithNoDefaultEndTakesTheGranterLimit(): void
    {
        $limit = self::at('2099-06-30 23:59:59');

        $this->assertEquals($limit, $this->expiry()->validUntilFor(ScopeNode::programa('BAJA'), null, $limit));
    }

    /** A granter with no limit (a platform admin whose grant never ends) may give any future end. */
    public function testAGranterWithNoLimitMayGiveAnyFutureEnd(): void
    {
        $farAway = self::at('2199-01-01 00:00:00');

        $this->assertEquals($farAway, $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), $farAway, self::UNLIMITED));
    }
}
