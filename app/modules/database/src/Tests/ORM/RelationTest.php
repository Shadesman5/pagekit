<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use PHPUnit\Framework\TestCase;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\Relation\BelongsTo;
use Pagekit\Database\ORM\Relation\HasMany;
use Pagekit\Database\ORM\Relation\HasOne;

class RelationTest extends TestCase
{
    public function testBelongsToConstructorAcceptsTypedParameters(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $metadata = $this->createMock(Metadata::class);
        $targetMetadata = $this->createMock(Metadata::class);
        
        $metadata->method('getIdentifier')->willReturn('id');
        $manager->method('getMetadata')->willReturn($targetMetadata);
        $targetMetadata->method('getIdentifier')->willReturn('id');
        
        $mapping = [
            'name' => 'user',
            'targetEntity' => 'User',
            'keyFrom' => 'user_id'
        ];
        
        $relation = new BelongsTo($manager, $metadata, $mapping);
        
        $this->assertInstanceOf(BelongsTo::class, $relation);
    }
    
    public function testHasManyConstructorAcceptsTypedParameters(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $metadata = $this->createMock(Metadata::class);
        $targetMetadata = $this->createMock(Metadata::class);
        
        $metadata->method('getIdentifier')->willReturn('id');
        $manager->method('getMetadata')->willReturn($targetMetadata);
        $targetMetadata->method('getRelationMappings')->willReturn([]);
        
        $mapping = [
            'name' => 'posts',
            'targetEntity' => 'Post',
            'keyTo' => 'user_id',
            'orderBy' => ['created' => 'DESC']
        ];
        
        $relation = new HasMany($manager, $metadata, $mapping);
        
        $this->assertInstanceOf(HasMany::class, $relation);
    }
    
    public function testHasOneConstructorAcceptsTypedParameters(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $metadata = $this->createMock(Metadata::class);
        $targetMetadata = $this->createMock(Metadata::class);
        
        $metadata->method('getIdentifier')->willReturn('id');
        $metadata->method('getClass')->willReturn('User');
        $manager->method('getMetadata')->willReturn($targetMetadata);
        $targetMetadata->method('getRelationMappings')->willReturn([]);
        
        $mapping = [
            'name' => 'profile',
            'targetEntity' => 'Profile',
            'keyTo' => 'user_id'
        ];
        
        $relation = new HasOne($manager, $metadata, $mapping);
        
        $this->assertInstanceOf(HasOne::class, $relation);
    }
}
