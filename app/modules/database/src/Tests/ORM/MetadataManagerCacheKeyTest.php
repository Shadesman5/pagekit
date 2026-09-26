<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\Tests\ORM\Fixtures\CacheInvalidationEntity;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Util\CacheKeyUtil;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Metadata cache ids are class names. Those names contain backslashes, which a PSR-6 key rejects.
 */
final class MetadataManagerCacheKeyTest extends TestCase
{
    public function testACachedClassNameDropsReservedCharacters(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn([]);

        $key = null;
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturnCallback(function (string $id) use ($item, &$key): CacheItemInterface {
                $key = $id;

                return $item;
            });

        $manager = new MetadataManager(
            $this->createMock(Connection::class),
            $this->createMock(EventDispatcherInterface::class),
        );
        $manager->setCache($cache);

        $metadata = $manager->get(CacheInvalidationEntity::class);

        $this->assertSame(CacheInvalidationEntity::class, $metadata->getClass());
        $this->assertIsString($key);
        $this->assertSame($key, CacheKeyUtil::sanitize($key));
        $this->assertStringContainsString(
            'Pagekit_Database_Tests_ORM_Fixtures_CacheInvalidationEntity',
            $key,
        );
        $this->assertStringNotContainsString('\\', $key);
    }
}
