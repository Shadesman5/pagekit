<?php

namespace Pagekit\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Cache\Adapter\ArrayAdapter;
use Pagekit\Cache\Adapter\FilesystemAdapter;
use Pagekit\Cache\Adapter\PhpFilesAdapter;
use Pagekit\Cache\Adapter\NullAdapter;

/**
 * PSR-6 Adapter Test Suite
 */
class Psr6AdapterTest extends TestCase
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
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Test ArrayAdapter (in-memory cache)
     */
    public function testArrayAdapter(): void
    {
        $cache = new ArrayAdapter();
        
        // Test save and fetch
        $this->assertTrue($cache->save('test_key', 'test_value'));
        $this->assertEquals('test_value', $cache->fetch('test_key'));
        
        // Test contains
        $this->assertTrue($cache->contains('test_key'));
        $this->assertFalse($cache->contains('non_existent'));
        
        // Test delete
        $this->assertTrue($cache->delete('test_key'));
        $this->assertFalse($cache->contains('test_key'));
        
        // Test complex data
        $data = ['array' => ['nested' => 'value'], 'number' => 42];
        $cache->save('complex', $data);
        $this->assertEquals($data, $cache->fetch('complex'));
        
        // Test flush
        $cache->save('key1', 'value1');
        $cache->save('key2', 'value2');
        $this->assertTrue($cache->flushAll());
        $this->assertFalse($cache->contains('key1'));
        $this->assertFalse($cache->contains('key2'));
    }

    /**
     * Test FilesystemAdapter
     */
    public function testFilesystemAdapter(): void
    {
        $cache = new FilesystemAdapter($this->cacheDir);
        
        // Test save and fetch
        $this->assertTrue($cache->save('test_key', 'test_value'));
        $this->assertEquals('test_value', $cache->fetch('test_key'));
        
        // Test persistence (create new instance)
        $cache2 = new FilesystemAdapter($this->cacheDir);
        $this->assertEquals('test_value', $cache2->fetch('test_key'));
        
        // Test TTL
        $cache->save('ttl_key', 'ttl_value', 1);
        $this->assertEquals('ttl_value', $cache->fetch('ttl_key'));
        sleep(2);
        $this->assertFalse($cache->fetch('ttl_key'));
        
        // Test namespace
        $cache->setNamespace('test_ns');
        $cache->save('ns_key', 'ns_value');
        $this->assertEquals('ns_value', $cache->fetch('ns_key'));
        
        // Different namespace shouldn't see the key
        $cache->setNamespace('other_ns');
        $this->assertFalse($cache->fetch('ns_key'));
    }

    /**
     * Test PhpFilesAdapter
     */
    public function testPhpFilesAdapter(): void
    {
        $cache = new PhpFilesAdapter($this->cacheDir);
        
        // Test save and fetch
        $this->assertTrue($cache->save('test_key', 'test_value'));
        $this->assertEquals('test_value', $cache->fetch('test_key'));
        
        // Test complex PHP data
        $object = new \stdClass();
        $object->property = 'value';
        $object->array = [1, 2, 3];
        
        $cache->save('object_key', $object);
        $retrieved = $cache->fetch('object_key');
        $this->assertEquals($object->property, $retrieved->property);
        $this->assertEquals($object->array, $retrieved->array);
        
        // Test performance advantage with arrays
        $largeArray = array_fill(0, 1000, 'value');
        $cache->save('large_array', $largeArray);
        $this->assertEquals($largeArray, $cache->fetch('large_array'));
    }

    /**
     * Test NullAdapter (no-op cache)
     */
    public function testNullAdapter(): void
    {
        $cache = new NullAdapter();
        
        // Save should return true but not actually store
        $this->assertTrue($cache->save('test_key', 'test_value'));
        
        // Fetch should always return false
        $this->assertFalse($cache->fetch('test_key'));
        
        // Contains should always return false
        $this->assertFalse($cache->contains('test_key'));
        
        // Delete should return true
        $this->assertTrue($cache->delete('test_key'));
        
        // Flush should return true
        $this->assertTrue($cache->flushAll());
    }

    /**
     * Test CacheInterface API (fetch, save, contains, delete, flushAll)
     */
    public function testCacheInterfaceApi(): void
    {
        $cache = new ArrayAdapter();
        
        // Test multiple save/fetch via CacheInterface
        $cache->save('key1', 'value1');
        $cache->save('key2', 'value2');
        $cache->save('key3', 'value3');
        
        // Fetch individually (CacheInterface has no fetchMultiple)
        $this->assertEquals('value1', $cache->fetch('key1'));
        $this->assertEquals('value2', $cache->fetch('key2'));
        $this->assertFalse($cache->fetch('non_existent'));
        
        // Delete via PSR-6 deleteItems
        $this->assertTrue($cache->deleteItems(['key1', 'key2']));
        $this->assertFalse($cache->contains('key1'));
        $this->assertFalse($cache->contains('key2'));
        $this->assertTrue($cache->contains('key3'));
    }

    /**
     * Test namespace isolation
     */
    public function testNamespaceIsolation(): void
    {
        $cache = new ArrayAdapter();
        
        // Set first namespace
        $cache->setNamespace('namespace1');
        $cache->save('key', 'value1');
        
        // Set second namespace
        $cache->setNamespace('namespace2');
        $cache->save('key', 'value2');
        
        // Check isolation
        $cache->setNamespace('namespace1');
        $this->assertEquals('value1', $cache->fetch('key'));
        
        $cache->setNamespace('namespace2');
        $this->assertEquals('value2', $cache->fetch('key'));
        
        // Delete in one namespace shouldn't affect other
        $cache->delete('key');
        $this->assertFalse($cache->fetch('key'));
        
        $cache->setNamespace('namespace1');
        $this->assertEquals('value1', $cache->fetch('key'));
    }

    /**
     * Performance benchmark test
     */
    public function testPerformanceBenchmark(): void
    {
        $adapters = [
            'Array' => new ArrayAdapter(),
            'Filesystem' => new FilesystemAdapter($this->cacheDir . '/fs'),
            'PhpFiles' => new PhpFilesAdapter($this->cacheDir . '/php'),
        ];
        
        $iterations = 100;
        $results = [];
        
        foreach ($adapters as $name => $adapter) {
            // Write benchmark
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $adapter->save("key_$i", "value_$i");
            }
            $writeTime = microtime(true) - $start;
            
            // Read benchmark
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $adapter->fetch("key_$i");
            }
            $readTime = microtime(true) - $start;
            
            $results[$name] = [
                'write' => $writeTime,
                'read' => $readTime,
            ];
        }
        
        // Assert that operations completed (basic sanity check)
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