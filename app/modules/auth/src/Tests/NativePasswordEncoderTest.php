<?php

declare(strict_types=1);

namespace Pagekit\Auth\Tests;

use Pagekit\Auth\Encoder\NativePasswordEncoder;
use PHPUnit\Framework\TestCase;

class NativePasswordEncoderTest extends TestCase
{
    private NativePasswordEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new NativePasswordEncoder();
    }

    public function testHashProducesBcryptWithCostTen(): void
    {
        $hash = $this->encoder->hash('s3cr3t-p4ssw0rd');

        // A bcrypt cost-10 digest always carries the "$2y$10$" prefix; asserting it
        // pins both the algorithm and the cost against IncrementInteger/algorithm mutants.
        $this->assertStringStartsWith('$2y$10$', $hash);

        $info = password_get_info($hash);
        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(10, $info['options']['cost']);

        // The produced digest must be a genuine, verifiable password_hash() output.
        $this->assertTrue(password_verify('s3cr3t-p4ssw0rd', $hash));
    }

    public function testVerifyReturnsTrueForMatchingPassword(): void
    {
        $hash = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertTrue($this->encoder->verify($hash, 'correct-horse'));
    }

    public function testVerifyReturnsFalseForWrongPassword(): void
    {
        $hash = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertFalse($this->encoder->verify($hash, 'battery-staple'));
    }

    public function testVerifyThrowsWhenSaltProvided(): void
    {
        $hash = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 10]);

        // The non-null salt guard must reject external salts before verification,
        // killing the `null !== $salt` condition mutant.
        $this->expectException(\InvalidArgumentException::class);
        $this->encoder->verify($hash, 'correct-horse', 'a-separate-salt');
    }
}
