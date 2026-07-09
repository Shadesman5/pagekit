<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\User\Event\LoginAttemptListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Unit tests for the brute-force throttle {@see LoginAttemptListener}.
 *
 * The listener is backed only by an injected PSR-6 {@see CacheItemPoolInterface},
 * so it is fully unit-testable: a mocked pool hands back a mocked
 * {@see CacheItemInterface} whose isHit()/get() drive every branch, with no
 * kernel, container or database. The {@see AuthenticateEvent} is a plain value
 * object (its base ctor only stores a name + parameters), so it is constructed
 * for real rather than mocked.
 *
 * `__()` resolution: ticket discovery note 5 assumed the user listeners import
 * `use function Pagekit\__;` and thus need a namespaced stub. The shipped
 * `LoginAttemptListener` does NOT import it — it calls `__('Slow down a bit.')`
 * UNQUALIFIED, so (exactly like `User` in Step 5) PHP's fallback rule resolves it
 * to the GLOBAL `\__()`. {@see setUp} pulls in the shared passthrough stub from
 * Tests/bootstrap.php; no `Pagekit\__()` stub is required.
 *
 * Boundary strategy for `count($attempts) >= ATTEMPTS && (clock->now() - $last) < DELAY`:
 * the two wall-clock `count === ATTEMPTS` cases isolate the *time* comparison by
 * using timestamps far inside (last = now) and far outside (last = now - 1000) the
 * DELAY window, so jitter can never flip the outcome. Their arrays are ordered so
 * the recent/old timestamp sits where end() reads it (the LAST slot), pinning
 * end() against reset()/current() mutants. The below-threshold case keeps every
 * timestamp recent so the only reason it does not throw is the count guard, which
 * pins the `>=` comparison and the `&&`. The two exact-gap cases inject a
 * {@see MockClock} so `(now - $last)` equals DELAY exactly (and DELAY - 1).
 *
 * Infection ignores (Step 2.1.8, see infection.json.dist `mutators`):
 *   - LogicalAnd, CastInt, DecrementInteger, IncrementInteger at
 *     onPreAuthenticate:45 — on `is_array($attempts) && $attempts !== [] ?
 *     (int) end($attempts) : 0`, the cast is a no-op for the int timestamps the
 *     listener stores, and the `&&` / `: 0` literal only take effect when
 *     `$attempts` is empty or non-array, where `count($attempts) >= ATTEMPTS` is
 *     already false (or count() itself TypeErrors). Equivalent.
 *
 * Step 2.1.9 (clock injection): the rate-limit boundary
 * `(clock->now() - $last) < DELAY` reads "now" via an injected PSR-20
 * {@see ClockInterface} (defaulting to a real {@see \Symfony\Component\Clock\Clock}),
 * so a {@see MockClock} makes the gap equal DELAY exactly. The boundary tests below
 * kill the LessThan mutant (`<` -> `<=`) that was previously ignored, so its
 * infection.json.dist entry has been removed.
 */
class LoginAttemptListenerTest extends TestCase
{
    protected function setUp(): void
    {
        // Global \__() translation stub: onPreAuthenticate() throws
        // AuthException(__('Slow down a bit.')) via the unqualified helper.
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // onPreAuthenticate(): guard + throttle boundaries.
    // -----------------------------------------------------------------------

    public function testOnPreAuthenticateReturnsEarlyForEmptyCredentials(): void
    {
        $cache = $this->pool();
        $cache->expects($this->never())->method('getItem');

        $this->listener($cache)->onPreAuthenticate($this->event([]));
    }

    public function testOnPreAuthenticateReturnsEarlyWhenUsernameMissing(): void
    {
        $cache = $this->pool();
        $cache->expects($this->never())->method('getItem');

        $this->listener($cache)->onPreAuthenticate($this->event(['password' => 'secret']));
    }

    public function testOnPreAuthenticatePassesWhenNoPreviousAttempts(): void
    {
        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->with('auth.login_attempts_alice')
            ->willReturn($this->missItem());

        $this->listener($cache)->onPreAuthenticate($this->event(['username' => 'alice']));
    }

    public function testOnPreAuthenticatePassesWhenAttemptsBelowThreshold(): void
    {
        // Four recent attempts: count (4) < ATTEMPTS (5) is the ONLY reason no
        // throw happens, so a weakened count comparison (or `&&` -> `||`) throws.
        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturn($this->hitItem(array_fill(0, 4, time())));

        $this->listener($cache)->onPreAuthenticate($this->event(['username' => 'alice']));
    }

    public function testOnPreAuthenticateThrowsWhenThresholdReachedAndLastAttemptRecent(): void
    {
        $now = time();
        // count === ATTEMPTS, last attempt (end of array) is now -> within DELAY.
        $attempts = [$now - 1000, $now - 1000, $now - 1000, $now - 1000, $now];

        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->with('auth.login_attempts_alice')
            ->willReturn($this->hitItem($attempts));

        $listener = $this->listener($cache);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Slow down a bit.');

        $listener->onPreAuthenticate($this->event(['username' => 'alice']));
    }

    public function testOnPreAuthenticatePassesWhenThresholdReachedButLastAttemptOld(): void
    {
        $now = time();
        // count === ATTEMPTS, but the last attempt (end of array) is well older
        // than DELAY. First slot is recent so an end()->reset() mutant would throw.
        $attempts = [$now, $now, $now, $now, $now - 1000];

        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturn($this->hitItem($attempts));

        $this->listener($cache)->onPreAuthenticate($this->event(['username' => 'alice']));
    }

    public function testOnPreAuthenticatePassesWhenGapExactlyEqualsDelay(): void
    {
        // Freeze "now" so the gap since the last attempt equals DELAY exactly:
        // (now - last) === DELAY. With `<` this does NOT throttle (5 < 5 is false),
        // so no exception is thrown. The LessThan mutant (`<` -> `<=`) would make
        // 5 <= 5 true and throw here.
        $now = 1_700_000_000; // arbitrary fixed instant
        $clock = new MockClock(new \DateTimeImmutable('@' . $now));
        $last = $now - LoginAttemptListener::DELAY;
        $attempts = [$now - 1000, $now - 1000, $now - 1000, $now - 1000, $last];

        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->with('auth.login_attempts_alice')
            ->willReturn($this->hitItem($attempts));

        // No exception at the exact boundary.
        $this->listener($cache, $clock)->onPreAuthenticate($this->event(['username' => 'alice']));
    }

    public function testOnPreAuthenticateThrowsWhenGapOneSecondBelowDelay(): void
    {
        // One second inside the window: (now - last) === DELAY - 1 < DELAY -> throttle.
        $now = 1_700_000_000; // arbitrary fixed instant
        $clock = new MockClock(new \DateTimeImmutable('@' . $now));
        $last = $now - (LoginAttemptListener::DELAY - 1);
        $attempts = [$now - 1000, $now - 1000, $now - 1000, $now - 1000, $last];

        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->with('auth.login_attempts_alice')
            ->willReturn($this->hitItem($attempts));

        $listener = $this->listener($cache, $clock);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Slow down a bit.');

        $listener->onPreAuthenticate($this->event(['username' => 'alice']));
    }

    // -----------------------------------------------------------------------
    // onAuthFailure(): append clock->now() to the array + save the item.
    // -----------------------------------------------------------------------

    public function testOnAuthFailureAppendsTimestampAndSaves(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn([100, 200]);
        $item->expects($this->once())
            ->method('set')
            ->with($this->callback(function (mixed $stored): bool {
                if (!is_array($stored) || count($stored) !== 3) {
                    return false;
                }
                $appended = $stored[2];

                return $stored[0] === 100
                    && $stored[1] === 200
                    && is_int($appended)
                    && abs($appended - time()) <= 2;
            }))
            ->willReturnSelf();

        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('getItem')
            ->with('auth.login_attempts_alice')
            ->willReturn($item);
        $cache->expects($this->once())->method('save')->with($item)->willReturn(true);

        $this->listener($cache)->onAuthFailure($this->event(['username' => 'alice']));
    }

    public function testOnAuthFailureStartsFreshArrayWhenNoPreviousAttempts(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->expects($this->never())->method('get');
        $item->expects($this->once())
            ->method('set')
            ->with($this->callback(function (mixed $stored): bool {
                if (!is_array($stored) || count($stored) !== 1) {
                    return false;
                }
                $only = $stored[0];

                return is_int($only) && abs($only - time()) <= 2;
            }))
            ->willReturnSelf();

        $cache = $this->pool();
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->once())->method('save')->with($item)->willReturn(true);

        $this->listener($cache)->onAuthFailure($this->event(['username' => 'alice']));
    }

    public function testOnAuthFailureReturnsEarlyWhenUsernameMissing(): void
    {
        $cache = $this->pool();
        $cache->expects($this->never())->method('getItem');
        $cache->expects($this->never())->method('save');

        $this->listener($cache)->onAuthFailure($this->event([]));
    }

    public function testOnAuthFailureReturnsEarlyWhenCredentialsPresentButUsernameMissing(): void
    {
        // Non-empty credentials without a 'username' key: the `!$credentials or
        // !isset($credentials['username'])` guard must STILL return early. A
        // LogicalLowerOr mutant (`or` -> `and`) falls through to
        // getCacheKey($credentials['username']) and dereferences the missing key.
        $cache = $this->pool();
        $cache->expects($this->never())->method('getItem');
        $cache->expects($this->never())->method('save');

        $this->listener($cache)->onAuthFailure($this->event(['password' => 'secret']));
    }

    // -----------------------------------------------------------------------
    // onAuthSuccess(): delete the cache item (and sanitize its key).
    // -----------------------------------------------------------------------

    public function testOnAuthSuccessDeletesCacheItem(): void
    {
        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('deleteItem')
            ->with('auth.login_attempts_alice')
            ->willReturn(true);

        $this->listener($cache)->onAuthSuccess($this->event(['username' => 'alice']));
    }

    public function testOnAuthSuccessSanitizesReservedCharactersInCacheKey(): void
    {
        // getCacheKey() runs the key through CacheKeyUtil::sanitize(), replacing
        // every PSR-6 reserved character (: \ / @ { } ( )) with an underscore.
        $cache = $this->pool();
        $cache->expects($this->once())
            ->method('deleteItem')
            ->with('auth.login_attempts_a_b_c_d_e_f_g_h_i')
            ->willReturn(true);

        $this->listener($cache)->onAuthSuccess($this->event(['username' => "a:b/c@d\\e{f}g(h)i"]));
    }

    public function testOnAuthSuccessReturnsEarlyWhenUsernameMissing(): void
    {
        $cache = $this->pool();
        $cache->expects($this->never())->method('deleteItem');

        $this->listener($cache)->onAuthSuccess($this->event(['password' => 'secret']));
    }

    // -----------------------------------------------------------------------
    // getCacheKey(): protected-visibility contract.
    // -----------------------------------------------------------------------

    public function testGetCacheKeyStaysReachableFromSubclass(): void
    {
        // getCacheKey() is `protected` so listener subclasses can derive the same
        // throttle key (e.g. to pre-seed or inspect it). Reaching it from a subclass
        // pins that visibility: the ProtectedVisibility mutant narrows it to private
        // and this forwarding call fatals.
        $listener = new class ($this->pool()) extends LoginAttemptListener {
            public function exposeCacheKey(string $username): string
            {
                return $this->getCacheKey($username);
            }
        };

        $this->assertSame('auth.login_attempts_alice', $listener->exposeCacheKey('alice'));
    }

    // -----------------------------------------------------------------------
    // subscribe(): event -> handler mapping.
    // -----------------------------------------------------------------------

    public function testSubscribeMapsAuthEventsToHandlers(): void
    {
        $this->assertSame([
            'auth.pre_authenticate' => 'onPreAuthenticate',
            'auth.failure' => 'onAuthFailure',
            'auth.success' => 'onAuthSuccess',
        ], $this->listener($this->pool())->subscribe());
    }

    // -----------------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------------

    private function listener(CacheItemPoolInterface $cache, ?ClockInterface $clock = null): LoginAttemptListener
    {
        return $clock === null
            ? new LoginAttemptListener($cache)
            : new LoginAttemptListener($cache, $clock);
    }

    /**
     * @param array<string, string> $credentials
     */
    private function event(array $credentials): AuthenticateEvent
    {
        return new AuthenticateEvent('auth.pre_authenticate', $credentials);
    }

    private function pool(): CacheItemPoolInterface&MockObject
    {
        return $this->createMock(CacheItemPoolInterface::class);
    }

    /**
     * A cache item reporting a hit that carries the given attempt timestamps.
     *
     * @param array<int, int> $attempts
     */
    private function hitItem(array $attempts): CacheItemInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($attempts);

        return $item;
    }

    /**
     * A cache item reporting a miss; get() must never be consulted.
     */
    private function missItem(): CacheItemInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->expects($this->never())->method('get');

        return $item;
    }
}
