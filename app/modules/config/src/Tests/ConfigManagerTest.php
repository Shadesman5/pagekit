<?php

declare(strict_types=1);

namespace Pagekit\Config\Tests;

use Pagekit\Config\ConfigManager;
use PHPUnit\Framework\TestCase;

class ConfigManagerTest extends TestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testGet(): void
    {
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Pagekit\Database\Connection
     */
    protected function getConnection(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this
            ->getMockBuilder('Pagekit\Database\Connection')
            ->disableOriginalConstructor()
            ->onlyMethods(
                [
                    'fetchAssoc',
                    'fetchAll',
                    'executeQuery',
                    'getDatabasePlatform',
                    'update',
                    'insert',
                    'delete',
                    'isConnected',
                ]
            )
            ->getMock();

        $mock->method('isConnected')
             ->willReturn(true);

        return $mock;
    }

    protected function getConfig(?\Pagekit\Database\Connection $connection = null): ConfigManager
    {
        $connection = $connection ?: $this->getConnection();

        return new ConfigManager($connection, ['table' => 'test']);
    }
}
