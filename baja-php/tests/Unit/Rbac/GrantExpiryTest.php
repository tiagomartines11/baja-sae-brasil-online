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
 * THE RULE
 *
 * A role given on an event, or on one of its provas or queues, is for that
 * event. Unless the granter says otherwise, it ends DAYS days after the
 * event's last day (23:59:59 that day). DAYS is configurable; 7 here.
 *
 * If the event's date text cannot be read, the grant is refused unless the
 * granter gives an end themselves: the system does not guess when access
 * to an event should stop.
 *
 * A grant can never be made already ended. Grants above an event (a
 * program, the platform) have no default end.
 *
 * Here "now" is 1 March 2099 and the events are made up: 96BR on 10-12
 * March 2099, 96SE with no readable date.
 */
final class GrantExpiryTest extends TestCase
{
    private function expiry(int $days = 7): GrantExpiry
    {
        $dates = ['96BR' => '10 a 12 de março de 2099', '96SE' => 'a definir'];
        return new GrantExpiry(
            fn(string $eventoId) => $dates[$eventoId] ?? null,
            new FixedClock('2099-03-01 10:00:00'),
            $days
        );
    }

    /** A judge's role on a prova ends 7 days after the event's last day, at the end of that day. */
    public function testAGrantInsideAnEventEndsDaysAfterIt(): void
    {
        $validUntil = $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), null);

        $this->assertSame('2099-03-19 23:59:59', $validUntil?->format('Y-m-d H:i:s'));
    }

    /** The same for the event itself and for its queues. */
    public function testTheSameForTheEventAndItsQueues(): void
    {
        $this->assertSame('2099-03-19', $this->expiry()->validUntilFor(ScopeNode::evento('96BR'), null)?->format('Y-m-d'));
        $this->assertSame('2099-03-19', $this->expiry()->validUntilFor(ScopeNode::fila('96BR', 1), null)?->format('Y-m-d'));
    }

    /** The number of days is a setting. */
    public function testTheNumberOfDaysIsConfigurable(): void
    {
        $this->assertSame('2099-03-12', $this->expiry(0)->validUntilFor(ScopeNode::evento('96BR'), null)?->format('Y-m-d'));
        $this->assertSame('2099-04-11', $this->expiry(30)->validUntilFor(ScopeNode::evento('96BR'), null)?->format('Y-m-d'));
    }

    /** An end the granter gives is kept as it is. */
    public function testAnExplicitEndIsKept(): void
    {
        $explicit = new DateTimeImmutable('2099-03-11 18:00:00');

        $this->assertEquals($explicit, $this->expiry()->validUntilFor(ScopeNode::prova('96BR', 'ZZA'), $explicit));
    }

    /** No readable date and no explicit end: refused, not guessed. */
    public function testAnUnreadableEventDateNeedsAnExplicitEnd(): void
    {
        try {
            $this->expiry()->validUntilFor(ScopeNode::prova('96SE', 'ZZA'), null);
            $this->fail('A grant on an event with no readable date was given an end by guessing.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::VALID_UNTIL_REQUIRED, $e->reason);
        }
    }

    /** With an explicit end, the same grant is fine. */
    public function testAnUnreadableEventDateIsFineWithAnExplicitEnd(): void
    {
        $explicit = new DateTimeImmutable('2099-06-01 00:00:00');

        $this->assertEquals($explicit, $this->expiry()->validUntilFor(ScopeNode::evento('96SE'), $explicit));
    }

    /** An end in the past, or exactly now, is refused: the grant would be born ended. */
    public function testAnEndThatIsNotInTheFutureIsRefused(): void
    {
        foreach (['2099-02-28 00:00:00', '2099-03-01 10:00:00'] as $past) {
            try {
                $this->expiry()->validUntilFor(ScopeNode::evento('96BR'), new DateTimeImmutable($past));
                $this->fail("valid_until $past was accepted.");
            } catch (GrantRefused $e) {
                $this->assertSame(GrantRefused::VALID_UNTIL_IN_PAST, $e->reason);
            }
        }
    }

    /** A grant on an event long over would default to an end already gone: refused too. */
    public function testADefaultEndAlreadyGoneIsRefused(): void
    {
        $expiry = new GrantExpiry(fn() => '20 a 24 de abril de 2022', new FixedClock('2099-03-01 10:00:00'));

        $this->expectException(GrantRefused::class);
        $expiry->validUntilFor(ScopeNode::evento('22BR'), null);
    }

    /** Above an event there is no default end: a program manager's role lasts until revoked. */
    public function testGrantsAboveAnEventHaveNoDefaultEnd(): void
    {
        $this->assertNull($this->expiry()->validUntilFor(ScopeNode::programa('BAJA'), null));
        $this->assertNull($this->expiry()->validUntilFor(ScopeNode::platform(), null));
    }
}
