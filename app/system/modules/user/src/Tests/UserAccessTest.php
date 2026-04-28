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
