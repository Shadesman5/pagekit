<?php

declare(strict_types=1);

namespace Pagekit\Auth\Tests;

use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Event\Event;
use Pagekit\Auth\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the auth event value objects.
 *
 * The auth {@see Event} base forwards its name to the platform event base via
 * `parent::__construct($name)`; dropping that call leaves the typed `$name`
 * property uninitialised so `getName()` fatals. Asserting the propagated name on
 * both the base event and its subclasses pins that constructor chain (kills the
 * MethodCallRemoval mutant on `parent::__construct()`), while the user/credentials
 * assertions pin the DTO accessors the same constructors populate.
 */
class EventTest extends TestCase
{
    public function testEventExposesNameAndUser(): void
    {
        $user = $this->createMock(UserInterface::class);

        $event = new Event('auth.event', $user);

        $this->assertSame('auth.event', $event->getName());
        $this->assertSame($user, $event->getUser());
    }

    public function testEventDefaultsToNoUser(): void
    {
        $event = new Event('auth.event');

        $this->assertSame('auth.event', $event->getName());
        $this->assertNull($event->getUser());
    }

    public function testAuthenticateEventCarriesNameCredentialsAndUser(): void
    {
        $user = $this->createMock(UserInterface::class);
        $credentials = ['username' => 'alice', 'password' => 's3cr3t'];

        $event = new AuthenticateEvent('auth.pre_authenticate', $credentials, $user);

        $this->assertSame('auth.pre_authenticate', $event->getName());
        $this->assertSame($credentials, $event->getCredentials());
        $this->assertSame($user, $event->getUser());
    }

    public function testAuthorizeEventCarriesNameAndUser(): void
    {
        $user = $this->createMock(UserInterface::class);

        $event = new AuthorizeEvent('auth.authorize', $user);

        $this->assertSame('auth.authorize', $event->getName());
        $this->assertSame($user, $event->getUser());
    }
}
