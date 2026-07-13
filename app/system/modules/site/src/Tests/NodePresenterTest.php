<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Application\UrlProvider;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Site\Model\Node;
use Pagekit\Site\NodePresenter;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers NodePresenter — the constructor-DI replacement for the Node entity's
 * former static service-locator reach-through. Exercises URL generation, the
 * published/access gate and the enriched toArray() shape while staying clear of
 * the ORM: the Node's kernel-bound methods (toArray/hasAccess) are stubbed and
 * the plain public columns are set directly, so no database is required.
 */
class NodePresenterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testGetUrlResolvesNodeLinkThroughUrlProvider(): void
    {
        $node = $this->createNodeMock();
        $node->link = '@blog/id';

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@blog/id', [], UrlGenerator::ABSOLUTE_PATH)
            ->willReturn('/blog/1');

        $presenter = new NodePresenter($url, $this->createMock(User::class));

        $this->assertSame('/blog/1', $presenter->getUrl($node));
    }

    public function testGetUrlForwardsReferenceType(): void
    {
        $node = $this->createNodeMock();
        $node->link = 'about';

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('about', [], UrlProvider::BASE_PATH)
            ->willReturn('/about');

        $presenter = new NodePresenter($url, $this->createMock(User::class));

        $this->assertSame('/about', $presenter->getUrl($node, UrlProvider::BASE_PATH));
    }

    public function testIsAccessibleReturnsTrueForPublishedNodeWhenUserHasAccess(): void
    {
        $node = $this->createNodeMock();
        $node->status = 1;
        $node->method('hasAccess')->willReturn(true);

        $presenter = new NodePresenter($this->createMock(UrlProvider::class), $this->createMock(User::class));

        $this->assertTrue($presenter->isAccessible($node));
    }

    public function testIsAccessibleReturnsFalseForPublishedNodeWhenUserLacksAccess(): void
    {
        $node = $this->createNodeMock();
        $node->status = 1;
        $node->method('hasAccess')->willReturn(false);

        $presenter = new NodePresenter($this->createMock(UrlProvider::class), $this->createMock(User::class));

        $this->assertFalse($presenter->isAccessible($node));
    }

    public function testIsAccessibleReturnsFalseForUnpublishedNodeWithoutCheckingAccess(): void
    {
        $node = $this->createNodeMock();
        $node->status = 0;
        $node->expects($this->never())->method('hasAccess');

        $presenter = new NodePresenter($this->createMock(UrlProvider::class), $this->createMock(User::class));

        $this->assertFalse($presenter->isAccessible($node));
    }

    public function testIsAccessibleFallsBackToInjectedCurrentUser(): void
    {
        $currentUser = $this->createMock(User::class);

        $node = $this->createNodeMock();
        $node->status = 1;
        $node->expects($this->once())
            ->method('hasAccess')
            ->with($currentUser)
            ->willReturn(true);

        $presenter = new NodePresenter($this->createMock(UrlProvider::class), $currentUser);

        $this->assertTrue($presenter->isAccessible($node));
    }

    public function testIsAccessiblePrefersExplicitUserOverInjectedUser(): void
    {
        $currentUser = $this->createMock(User::class);
        $explicitUser = $this->createMock(User::class);

        $node = $this->createNodeMock();
        $node->status = 1;
        $node->expects($this->once())
            ->method('hasAccess')
            ->with($explicitUser)
            ->willReturn(true);

        $presenter = new NodePresenter($this->createMock(UrlProvider::class), $currentUser);

        $this->assertTrue($presenter->isAccessible($node, $explicitUser));
    }

    public function testToArrayIncludesEnrichedUrlAndAccessibleKeys(): void
    {
        $node = $this->createNodeMock();
        $node->link = '@page/id';
        $node->status = 1;
        $node->method('hasAccess')->willReturn(true);
        $node->method('toArray')->willReturnCallback(
            static fn (array $data = []): array => $data + ['id' => 7, 'title' => 'Home']
        );

        $url = $this->createMock(UrlProvider::class);
        $url->expects($this->once())
            ->method('get')
            ->with('@page/id', [], UrlProvider::BASE_PATH)
            ->willReturn('/home');

        $presenter = new NodePresenter($url, $this->createMock(User::class));

        $result = $presenter->toArray($node);

        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('accessible', $result);
        $this->assertSame('/home', $result['url']);
        $this->assertTrue($result['accessible']);
        $this->assertSame(7, $result['id']);
    }

    /**
     * Builds a kernel-free Node double with only its ORM/access methods stubbed;
     * plain columns (link/status) are assigned directly on the returned instance.
     *
     * @return Node&MockObject
     */
    private function createNodeMock(): Node&MockObject
    {
        return $this->getMockBuilder(Node::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toArray', 'hasAccess'])
            ->getMock();
    }
}
