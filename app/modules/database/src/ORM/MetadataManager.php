<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Cache\CacheKeyUtil;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\Loader\LoaderInterface;
use Pagekit\Event\EventDispatcherInterface;
use Psr\Cache\CacheItemPoolInterface;

class MetadataManager
{
    protected Connection $connection;

    protected EventDispatcherInterface $events;

    protected ?LoaderInterface $loader = null;

    protected ?CacheItemPoolInterface $cache = null;

    /**
     * @var Metadata[]
     */
    protected array $metadata = [];

    /**
     * The cache prefix
     */
    protected string $prefix = 'Metadata.';

    /**
     * Constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection, EventDispatcherInterface $events)
    {
        $this->connection = $connection;
        $this->events = $events;
    }

    /**
     * Gets the database connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Sets the loader object used by the factory to create Metadata objects.
     *
     * @param LoaderInterface $loader
     */
    public function setLoader(LoaderInterface $loader): void
    {
        $this->loader = $loader;
    }

    /**
     * Gets the cache used for caching Metadata objects.
     */
    public function getCache(): ?CacheItemPoolInterface
    {
        return $this->cache;
    }

    /**
     * Sets the cache used for caching Metadata objects.
     *
     * @param CacheItemPoolInterface $cache
     */
    public function setCache(CacheItemPoolInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Checks if the metadata for a class is already loaded.
     *
     * @param  string $class
     */
    public function has(string $class): bool
    {
        return isset($this->metadata[$class]);
    }

    /**
     * Gets the metadata for the given class.
     *
     * @param  object|string $class
     */
    public function get(object|string $class): Metadata
    {
        $class = new \ReflectionClass($class);
        $name = $class->getName();

        if (!isset($this->metadata[$name])) {

            if ($this->cache) {

                $hash = filemtime($class->getFileName());
                foreach ($class->getTraits() as $trait) {
                    $hash += filemtime($trait->getFileName());
                }

                $current = $class;
                while ($parent = $current->getParentClass()) {
                    $hash += filemtime($parent->getFileName());
                    $current = $parent;
                }

                $id = CacheKeyUtil::sanitize(sprintf('%s%s.%s', $this->prefix, $hash, $name));

                $item = $this->cache->getItem($id);

                if ($item->isHit()) {
                    $config = $item->get();
                    $this->metadata[$name] = new Metadata($this, $name, $config);
                } else {
                    $metadata = $this->load($class);
                    $item->set($metadata->getConfig());
                    $this->cache->save($item);
                }

            } else {
                $this->load($class);
            }

            $this->subscribe($this->metadata[$name]);
        }

        return $this->metadata[$name];
    }

    /**
     * Loads the metadata of the given class.
     *
     * @param \ReflectionClass<object> $class
     */
    protected function load(\ReflectionClass $class): ?Metadata
    {
        $parent = null;

        foreach ($this->getParentClasses($class) as $class) {

            $name = $class->getName();

            if (isset($this->metadata[$name])) {
                $parent = $this->metadata[$name];

                continue;
            }

            $config = [];

            if ($parent) {

                foreach ($parent->getFields() as $field) {

                    if (!isset($field['inherited']) && !$parent->isMappedSuperclass()) {
                        $field['inherited'] = $parent->getClass();
                    }

                    $config['fields'][$field['name']] = $field;
                }

                foreach ($parent->getRelationMappings() as $relation) {

                    if (!isset($relation['inherited']) && !$parent->isMappedSuperclass()) {
                        $relation['inherited'] = $parent->getClass();
                    }

                    $config['relations'][$relation['name']] = $relation;
                }

                if ($identifier = $parent->getIdentifier()) {
                    $config['identifier'] = $identifier;
                }

                $config['events'] = $parent->getEvents();
            }

            $this->metadata[$name] = $parent = new Metadata($this, $name, $this->loader->load($class, $config));
        }

        return $parent;
    }

    /**
     * Get array of parent classes for the given class.
     *
     * @param  \ReflectionClass<object> $class
     * @return array<int, \ReflectionClass<object>>
     */
    protected function getParentClasses(\ReflectionClass $class): array
    {
        $parents = [$class];

        while ($parent = $class->getParentClass()) {

            if (!$this->loader->isTransient($parent)) {
                array_unshift($parents, $parent);
            }

            $class = $parent;
        }

        return $parents;
    }

    /**
     * Subscribes model lifecycle callbacks.
     *
     * @param Metadata $metadata
     */
    protected function subscribe(Metadata $metadata): void
    {
        foreach ($metadata->getEvents() as $event => $methods) {
            foreach ($methods as $method) {
                $this->events->on($metadata->getEventPrefix().'.'.$event, [$metadata->getClass(), $method]);
            }
        }
    }
}
