<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\ORM\PropertyTrait;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral coverage for the per-instance transient store that backs
 * {@see PropertyTrait} descriptor properties declared with `set: true`.
 *
 * Such a descriptor has no setter callable, so an assigned value is kept in a
 * private transient map (never as a dynamic property) and returned verbatim by
 * the next read, while the getter supplies the computed default until then.
 * {@see PropertyTrait::__clone()} freezes the current value of every descriptor
 * property into that same map on the copy.
 */
class PropertyTraitTest extends TestCase
{
    protected function setUp(): void
    {
        // Non-static getter: the trait binds it to the instance ($get->bindTo),
        // which is illegal for a static closure. `set: true` routes writes into
        // the transient store instead of a setter callable.
        PropertyTraitFixture::defineProperty('theme', function (): array {
            return ['mode' => 'light'];
        }, true);
    }

    public function testDescriptorGetterProvidesDefaultWhenUnset(): void
    {
        $fixture = new PropertyTraitFixture();

        $this->assertSame(['mode' => 'light'], $fixture->theme);
    }

    public function testSetOverridesDescriptorGetterOnRead(): void
    {
        $fixture = new PropertyTraitFixture();

        $fixture->theme = ['mode' => 'dark'];

        $this->assertSame(['mode' => 'dark'], $fixture->theme);
    }

    public function testCloneFreezesTheCurrentDescriptorValue(): void
    {
        $fixture = new PropertyTraitFixture();
        $fixture->theme = ['mode' => 'dark'];

        $clone = clone $fixture;

        $this->assertSame(['mode' => 'dark'], $clone->theme);
    }

    public function testTransientOverrideDoesNotLeakAcrossInstances(): void
    {
        $configured = new PropertyTraitFixture();
        $configured->theme = ['mode' => 'dark'];

        $fresh = new PropertyTraitFixture();

        $this->assertSame(['mode' => 'light'], $fresh->theme);
    }
}

/**
 * Inline consumer of {@see PropertyTrait} for the transient-store coverage. Not
 * an ORM entity: it carries no attributes and only exposes the trait so a
 * `set: true` virtual property can be exercised in isolation.
 *
 * @property array<string, string> $theme Virtual descriptor property resolved
 *           through the trait's magic accessors.
 */
class PropertyTraitFixture
{
    use PropertyTrait;
}
