<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the boolean expression evaluator and `User::hasAccess()`.
 *
 * Section A exercises the private static `evaluateBooleanExpression()` parser
 * directly via Reflection. Section B exercises `hasAccess()` with a partial
 * mock of `User` that stubs `isAdministrator()` and `hasPermission()` so the
 * tests stay pure unit tests (no DB, no kernel, no container).
 *
 * Infection: parser ignores in infection.json.dist are removed and every mutant
 * is killed outright — no ignores, no `Equivalent` entries. Two mechanisms
 * cooperate:
 *
 *   1. `failOnWarning="true"` in phpunit.xml.dist. Every parser character read is
 *      guarded by `$pos < $len` / `$pos >= $len`; a mutated boundary (`<`->`<=`,
 *      `>=`->`>`) reads `$exp[$len]`, an out-of-bounds access emitting an
 *      "Uninitialized string offset" warning that fails the run. That alone is
 *      not enough: tests must drive `$pos` to `$len` at those guards so the
 *      warning actually fires.
 *   2. Explicit boundary tests supply those triggering inputs so the
 *      out-of-bounds reads execute under each mutant:
 *        - testEvaluatorRejectsTrailingOrPipe       ('1|') -> LessThan parseOrExpr:275
 *        - testEvaluatorRejectsTrailingAndAmpersand  ('1&') -> LessThan parseAndExpr:291
 *        - testEvaluatorRejectsBareNot               ('!')  -> LessThan parseNotExpr:303
 *        - testEvaluatorRejectsUnclosedParenthesisAtEnd ('(1') -> GreaterThanOrEqualTo parseAtom:323
 *      The GreaterThanOrEqualTo mutant at parseAtom:314 is killed *behaviourally* by
 *      the three malformed-token tests above: the mutant lets `$ch = ""` fall
 *      through to the "Unexpected character ..." message rather than the original's
 *      "Unexpected end of expression", which those tests assert.
 *
 * The LogicalOr `||`->`&&` at parseAtom:323 is killed *behaviourally* (no warning
 * needed) by testEvaluatorRejectsUnclosedParenthesisWithTrailingInput ('(11'): the
 * mutant skips the "Missing closing parenthesis" throw and returns a bool, so the
 * expected exception never fires.
 *
 * The LessThanNegotiation `<`->`>` and LogicalAnd `&&`->`||` mutants on the while
 * conditions at parseOrExpr:273 / parseAndExpr:289 are killed directly by the
 * assertions in this suite once the warning no longer short-circuits the run. The
 * LoginAttemptListener / AccessListener / ReturnRemoval ignores that remain in
 * infection.json.dist are unrelated to this parser.
 */
class UserAccessTest extends TestCase
{
    /**
     * Invoke the private static `evaluateBooleanExpression()` via Reflection.
     */
    private function evaluate(string $exp): bool
    {
        $method = new \ReflectionMethod(User::class, 'evaluateBooleanExpression');

        return (bool) $method->invoke(null, $exp);
    }

    /**
     * Build a partial mock of `User` with deterministic authorization stubs.
     *
     * @param array<string, bool> $permissions Map of permission name => granted.
     */
    private function buildUser(bool $isAdministrator, array $permissions): User
    {
        $mock = $this->getMockBuilder(User::class)
            ->onlyMethods(['isAdministrator', 'hasPermission'])
            ->getMock();

        $mock->method('isAdministrator')->willReturn($isAdministrator);
        $mock->method('hasPermission')->willReturnCallback(
            static fn (string $permission): bool => $permissions[$permission] ?? false
        );

        return $mock;
    }

    // -----------------------------------------------------------------------
    // Section A: pure evaluator tests (private static via Reflection).
    // -----------------------------------------------------------------------

    public function testEvaluatorLiteralOne(): void
    {
        $this->assertTrue($this->evaluate('1'));
    }

    public function testEvaluatorLiteralZero(): void
    {
        $this->assertFalse($this->evaluate('0'));
    }

    public function testEvaluatorAndDoubleTrue(): void
    {
        $this->assertTrue($this->evaluate('1&&1'));
    }

    public function testEvaluatorAndDoubleFalse(): void
    {
        $this->assertFalse($this->evaluate('1&&0'));
    }

    public function testEvaluatorOrDoubleTrue(): void
    {
        $this->assertTrue($this->evaluate('0||1'));
    }

    public function testEvaluatorOrDoubleFalse(): void
    {
        $this->assertFalse($this->evaluate('0||0'));
    }

    public function testEvaluatorAndSingleTrue(): void
    {
        $this->assertTrue($this->evaluate('1&1'));
    }

    public function testEvaluatorAndSingleFalse(): void
    {
        $this->assertFalse($this->evaluate('1&0'));
    }

    public function testEvaluatorOrSingleTrue(): void
    {
        $this->assertTrue($this->evaluate('0|1'));
    }

    public function testEvaluatorOrSingleFalse(): void
    {
        $this->assertFalse($this->evaluate('0|0'));
    }

    public function testEvaluatorNotZero(): void
    {
        $this->assertTrue($this->evaluate('!0'));
    }

    public function testEvaluatorNotOne(): void
    {
        $this->assertFalse($this->evaluate('!1'));
    }

    public function testEvaluatorParenthesesOrTrue(): void
    {
        $this->assertTrue($this->evaluate('(1&&0)||1'));
    }

    public function testEvaluatorParenthesesAndFalse(): void
    {
        $this->assertFalse($this->evaluate('(0||0)&&1'));
    }

    /**
     * `&&` binds tighter than `||`. `1||0&&0` must parse as `1 || (0 && 0)`,
     * not `(1 || 0) && 0`.
     */
    public function testEvaluatorOperatorPrecedence(): void
    {
        $this->assertTrue($this->evaluate('1||0&&0'));
    }

    public function testEvaluatorNestedNotAndOr(): void
    {
        $this->assertTrue($this->evaluate('!(0||(1&&!1))'));
    }

    /**
     * A stray operator with no operand reaches parseAtom()'s final guard. Calling
     * the evaluator directly (rather than through hasAccess(), which catches every
     * Throwable and rethrows a generic InvalidArgumentException) is what pins the
     * Throw_ mutant: dropping the `throw` lets parseAtom() fall through to an
     * implicit null return, violating its `: bool` type with a TypeError instead
     * of the InvalidArgumentException asserted here.
     */
    public function testEvaluatorRejectsUnexpectedCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->evaluate('&');
    }

    // Boundary inputs: each drives `$pos` to `$len` at a specific parser guard
    // so Infection boundary mutants are killed. See the class docblock for the
    // mutant-to-test mapping.

    /**
     * A trailing single `|` ('1|') is consumed by parseOrExpr(), leaving
     * `$pos === $len` at the inner second-pipe guard (parseOrExpr:275). The
     * original `$pos < $len` short-circuits without reading; the `<`->`<=` mutant
     * reads `$exp[$len]` (out-of-bounds -> "Uninitialized string offset" warning),
     * which fails the run under failOnWarning="true". The input also reaches
     * parseAtom() at `$pos === $len`: the original throws "Unexpected end of
     * expression", whereas the parseAtom:314 `>=`->`>` mutant reads OOB and throws
     * "Unexpected character ...", so the asserted message pins that mutant too.
     */
    public function testEvaluatorRejectsTrailingOrPipe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected end of expression');

        $this->evaluate('1|');
    }

    /**
     * Mirror of the trailing-pipe case for the `&` branch: '1&' leaves
     * `$pos === $len` at the inner second-ampersand guard (parseAndExpr:291),
     * killing the `<`->`<=` mutant there via the out-of-bounds-read warning, and
     * again reaches parseAtom() at `$pos === $len` (pins parseAtom:314 through the
     * asserted "Unexpected end of expression" message).
     */
    public function testEvaluatorRejectsTrailingAndAmpersand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected end of expression');

        $this->evaluate('1&');
    }

    /**
     * A bare `!` recurses into parseNotExpr() with `$pos === $len`
     * (parseNotExpr:303). The original guard short-circuits; the `<`->`<=` mutant
     * reads `$exp[$len]` (out-of-bounds -> warning), killing it. Control then falls
     * to parseAtom() at `$pos === $len`, pinning parseAtom:314 via the asserted
     * "Unexpected end of expression" message.
     */
    public function testEvaluatorRejectsBareNot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected end of expression');

        $this->evaluate('!');
    }

    /**
     * An unclosed parenthesis at end-of-input ('(1') leaves `$pos === $len` at the
     * closing-paren guard (parseAtom:323). The original `$pos >= $len` short-circuits
     * the `||`; the `>=`->`>` mutant instead reads `$exp[$len]` (out-of-bounds ->
     * warning), killing it under failOnWarning="true".
     */
    public function testEvaluatorRejectsUnclosedParenthesisAtEnd(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing closing parenthesis');

        $this->evaluate('(1');
    }

    /**
     * An unclosed parenthesis whose inner expression is followed by more input
     * ('(11') pins the LogicalOr mutant at the closing-paren guard (parseAtom:323)
     * *behaviourally* -- no warning needed. Original: `$pos >= $len || $exp[$pos]
     * !== ')'` is true (the char is '1', not ')'), so it throws. The `||`->`&&`
     * mutant flips the condition to false, skips the throw, consumes the '1', and
     * the whole string parses to `true` with `$pos === $len`, so the expected
     * exception never fires and the mutant is killed.
     */
    public function testEvaluatorRejectsUnclosedParenthesisWithTrailingInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing closing parenthesis');

        $this->evaluate('(11');
    }

    // -----------------------------------------------------------------------
    // Section B: `hasAccess()` integration tests (partial mock).
    // -----------------------------------------------------------------------

    public function testHasAccessSimpleGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('read'));
    }

    public function testHasAccessSimpleDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('admin'));
    }

    /**
     * A bare (operator-free) permission must be resolved by hasPermission() and
     * returned immediately — NOT run through the boolean parser. A numeric-only
     * token exposes the difference: hasPermission('123') is false, but if the early
     * `return` is dropped (ReturnRemoval) the string falls through to the parser,
     * which strips '123' down to the literal '1' and evaluates it as true. Denying
     * '123' therefore pins that early return.
     */
    public function testHasAccessBarePermissionShortCircuitsBeforeParser(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('123'));
    }

    public function testHasAccessAndDoubleGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('read && write'));
    }

    public function testHasAccessAndDoubleDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('read && admin'));
    }

    public function testHasAccessAndSingleGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('read & write'));
    }

    public function testHasAccessAndSingleDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('read & admin'));
    }

    public function testHasAccessOrDoubleGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('read || admin'));
    }

    public function testHasAccessOrDoubleDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('admin || superadmin'));
    }

    public function testHasAccessOrSingleGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('read | admin'));
    }

    public function testHasAccessOrSingleDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('admin | superadmin'));
    }

    public function testHasAccessNotGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('!admin'));
    }

    public function testHasAccessNotDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('!read'));
    }

    public function testHasAccessParenthesesGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('(read && write) || admin'));
    }

    public function testHasAccessParenthesesDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('(admin && write) || superadmin'));
    }

    public function testHasAccessNestedGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('read && (write || admin)'));
    }

    public function testHasAccessNestedDenied(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertFalse($user->hasAccess('admin && (write || read)'));
    }

    public function testHasAccessComplexGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess('(read || admin) && (write || superadmin)'));
    }

    public function testHasAccessEmptyExpressionGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess(''));
    }

    public function testHasAccessNullExpressionGranted(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->assertTrue($user->hasAccess(null));
    }

    /**
     * Administrator short-circuit: an admin with **zero** permissions still
     * gets `true` for any expression, including ones referencing permissions
     * the admin does not hold.
     */
    public function testHasAccessAdministratorShortCircuit(): void
    {
        $user = $this->buildUser(true, []);

        $this->assertTrue($user->hasAccess('admin && write'));
    }

    public function testHasAccessInvalidExpressionThrows(): void
    {
        $user = $this->buildUser(false, ['read' => true, 'write' => true]);

        $this->expectException(\InvalidArgumentException::class);

        $user->hasAccess('&&& invalid');
    }
}
