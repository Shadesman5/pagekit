<?php

declare(strict_types=1);

namespace Pagekit\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

/**
 * PSR-6 Cache Pool Test Suite
 *
 * Tests Symfony cache adapters via the CacheItemPoolInterface contract.
 */
class CachePoolTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/pagekit-cache-test-' . uniqid();
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->cacheDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($dir . '/' . $object)) {
                        $this->recursiveDelete($dir . '/' . $object);
                    } else {
                        unlink($dir . '/' . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Test ArrayAdapter (in-memory cache) via PSR-6 API.
     */
    public function testArrayAdapter(): void
    {
        $pool = new ArrayAdapter();

        // Save and retrieve
        $item = $pool->getItem('test_key');
        $this->assertFalse($item->isHit());
        $item->set('test_value');
        $pool->save($item);

        $item = $pool->getItem('test_key');
        $this->assertTrue($item->isHit());
        $this->assertEquals('test_value', $item->get());

        // hasItem
        $this->assertTrue($pool->hasItem('test_key'));
        $this->assertFalse($pool->hasItem('non_existent'));

        // deleteItem
        $pool->deleteItem('test_key');
        $this->assertFalse($pool->hasItem('test_key'));

        // Complex data
        $data = ['array' => ['nested' => 'value'], 'number' => 42];
        $item = $pool->getItem('complex');
        $item->set($data);
        $pool->save($item);

        $item = $pool->getItem('complex');
        $this->assertEquals($data, $item->get());

        // Clear
        $item1 = $pool->getItem('key1');
        $item1->set('value1');
        $pool->save($item1);

        $item2 = $pool->getItem('key2');
        $item2->set('value2');
        $pool->save($item2);

        $this->assertTrue($pool->clear());
        $this->assertFalse($pool->hasItem('key1'));
        $this->assertFalse($pool->hasItem('key2'));
    }

    /**
     * Test FilesystemAdapter via PSR-6 API.
     */
    public function testFilesystemAdapter(): void
    {
        $pool = new FilesystemAdapter('', 0, $this->cacheDir . '/fs');

        // Save and retrieve
        $item = $pool->getItem('test_key');
        $item->set('test_value');
        $pool->save($item);

        $item = $pool->getItem('test_key');
        $this->assertTrue($item->isHit());
        $this->assertEquals('test_value', $item->get());

        // Persistence across instances
        $pool2 = new FilesystemAdapter('', 0, $this->cacheDir . '/fs');
        $item = $pool2->getItem('test_key');
        $this->assertTrue($item->isHit());
        $this->assertEquals('test_value', $item->get());

        // TTL expiration
        $item = $pool->getItem('ttl_key');
        $item->set('ttl_value');
        $item->expiresAfter(1);
        $pool->save($item);

        $item = $pool->getItem('ttl_key');
        $this->assertTrue($item->isHit());
        $this->assertEquals('ttl_value', $item->get());

        sleep(2);

        $item = $pool->getItem('ttl_key');
        $this->assertFalse($item->isHit());
    }

    /**
     * Test PhpFilesAdapter via PSR-6 API.
     */
    public function testPhpFilesAdapter(): void
    {
        $pool = new PhpFilesAdapter('', 0, $this->cacheDir . '/php');

        // Save and retrieve
        $item = $pool->getItem('test_key');
        $item->set('test_value');
        $pool->save($item);

        $item = $pool->getItem('test_key');
        $this->assertTrue($item->isHit());
        $this->assertEquals('test_value', $item->get());

        // Complex PHP data
        $object = new \stdClass();
        $object->property = 'value';
        $object->array = [1, 2, 3];

        $item = $pool->getItem('object_key');
        $item->set($object);
        $pool->save($item);

        $item = $pool->getItem('object_key');
        $retrieved = $item->get();
        $this->assertEquals($object->property, $retrieved->property);
        $this->assertEquals($object->array, $retrieved->array);

        // Large array
        $largeArray = array_fill(0, 1000, 'value');
        $item = $pool->getItem('large_array');
        $item->set($largeArray);
        $pool->save($item);

        $item = $pool->getItem('large_array');
        $this->assertEquals($largeArray, $item->get());
    }

    /**
     * Test NullAdapter (no-op cache) via PSR-6 API.
     */
    public function testNullAdapter(): void
    {
        $pool = new NullAdapter();

        // Save should succeed but not actually store
        $item = $pool->getItem('test_key');
        $item->set('test_value');
        $pool->save($item);

        // Item should never be a hit
        $item = $pool->getItem('test_key');
        $this->assertFalse($item->isHit());

        // hasItem should always return false
        $this->assertFalse($pool->hasItem('test_key'));

        // deleteItem should succeed
        $this->assertTrue($pool->deleteItem('test_key'));

        // clear should succeed
        $this->assertTrue($pool->clear());
    }

    /**
     * Test PSR-6 CacheItemPoolInterface contract operations.
     *
     * Exercises getItem, hasItem, save, deleteItem, clear using
     * only the PSR-6 contract.
     */
    public function testPsr6PoolContract(): void
    {
        $pool = new ArrayAdapter();

        // --- save / getItem / hasItem ---
        $item1 = $pool->getItem('key1');
        $item1->set('value1');
        $pool->save($item1);

        $item2 = $pool->getItem('key2');
        $item2->set('value2');
        $pool->save($item2);

        $item3 = $pool->getItem('key3');
        $item3->set('value3');
        $pool->save($item3);

        $this->assertEquals('value1', $pool->getItem('key1')->get());
        $this->assertEquals('value2', $pool->getItem('key2')->get());
        $this->assertFalse($pool->getItem('non_existent')->isHit());

        $this->assertTrue($pool->hasItem('key1'));
        $this->assertFalse($pool->hasItem('non_existent'));

        // --- deleteItem ---
        $this->assertTrue($pool->deleteItem('key1'));
        $this->assertFalse($pool->hasItem('key1'));
        $this->assertFalse($pool->getItem('key1')->isHit());
        // Remaining keys must be untouched
        $this->assertTrue($pool->hasItem('key2'));
        $this->assertTrue($pool->hasItem('key3'));

        // Delete a second key
        $this->assertTrue($pool->deleteItem('key2'));
        $this->assertFalse($pool->hasItem('key2'));
        $this->assertTrue($pool->hasItem('key3'));

        // --- clear ---
        $item = $pool->getItem('a');
        $item->set('1');
        $pool->save($item);

        $item = $pool->getItem('b');
        $item->set('2');
        $pool->save($item);

        $this->assertTrue($pool->clear());
        $this->assertFalse($pool->hasItem('a'));
        $this->assertFalse($pool->hasItem('b'));
        $this->assertFalse($pool->hasItem('key3'));
    }

    /**
     * Test that different namespaces isolate cache entries.
     */
    public function testNamespaceIsolation(): void
    {
        $pool1 = new FilesystemAdapter('namespace1', 0, $this->cacheDir . '/ns');
        $pool2 = new FilesystemAdapter('namespace2', 0, $this->cacheDir . '/ns');

        // Save same key in both namespaces
        $item = $pool1->getItem('key');
        $item->set('value1');
        $pool1->save($item);

        $item = $pool2->getItem('key');
        $item->set('value2');
        $pool2->save($item);

        // Each namespace returns its own value
        $this->assertEquals('value1', $pool1->getItem('key')->get());
        $this->assertEquals('value2', $pool2->getItem('key')->get());

        // Delete in one namespace shouldn't affect the other
        $pool2->deleteItem('key');
        $this->assertFalse($pool2->getItem('key')->isHit());
        $this->assertTrue($pool1->getItem('key')->isHit());
        $this->assertEquals('value1', $pool1->getItem('key')->get());
    }

    /**
     * Performance benchmark test via PSR-6 API.
     */
    public function testPerformanceBenchmark(): void
    {
        $pools = [
            'Array' => new ArrayAdapter(),
            'Filesystem' => new FilesystemAdapter('', 0, $this->cacheDir . '/fs-bench'),
            'PhpFiles' => new PhpFilesAdapter('', 0, $this->cacheDir . '/php-bench'),
        ];

        $iterations = 100;
        $results = [];

        foreach ($pools as $name => $pool) {
            // Write benchmark
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $item = $pool->getItem("key_$i");
                $item->set("value_$i");
                $pool->save($item);
            }
            $writeTime = microtime(true) - $start;

            // Read benchmark
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $pool->getItem("key_$i")->get();
            }
            $readTime = microtime(true) - $start;

            $results[$name] = [
                'write' => $writeTime,
                'read' => $readTime,
            ];
        }

        // Assert operations completed (basic sanity check)
        foreach ($results as $name => $times) {
            $this->assertGreaterThan(0, $times['write'], "$name write time should be positive");
            $this->assertGreaterThan(0, $times['read'], "$name read time should be positive");
        }

        // Optional: Output results for manual review
        if (getenv('VERBOSE_TESTS')) {
            echo "\nPerformance Results ($iterations iterations):\n";
            foreach ($results as $name => $times) {
                echo sprintf(
                    "%s: Write=%.4fs, Read=%.4fs\n",
                    $name,
                    $times['write'],
                    $times['read']
                );
            }
        }
    }
}
