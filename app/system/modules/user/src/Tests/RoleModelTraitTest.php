<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Doctrine\DBAL\Result;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\User\Model\Role;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see \Pagekit\User\Model\RoleModelTrait::saving()} lifecycle
 * handler, which reaches the injected {@see EntityManager} carried by an
 * {@see EntityEvent}.
 *
 * The handler assigns the next priority (MAX + 1) to brand-new roles only. The
 * connection and its result are mocked so both the "new role queries once" and
 * "persisted role touches nothing" branches are asserted without a database.
 */
class RoleModelTraitTest extends TestCase
{
    public function testSavingAssignsNextPriorityToANewRole(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(5);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT MAX(priority) + 1 FROM @system_role')
            ->willReturn($result);

        $manager = $this->createMock(EntityManager::class);
        $manager->method('getConnection')->willReturn($connection);

        $role = new Role();
        $role->id = null;
        $role->priority = 0;

        Role::saving(new EntityEvent('role.saving', $manager), $role);

        $this->assertSame(5, $role->priority, 'a new role must take the next available priority');
    }

    public function testSavingLeavesAnExistingRoleUntouched(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())
            ->method('getConnection');

        $role = new Role();
        $role->id = 3;
        $role->priority = 7;

        Role::saving(new EntityEvent('role.saving', $manager), $role);

        $this->assertSame(7, $role->priority, 'an already-persisted role keeps its priority and issues no query');
    }
}
