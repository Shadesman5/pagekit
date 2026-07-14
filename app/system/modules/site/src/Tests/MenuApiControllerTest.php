<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Filter\FilterManager;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\Kernel\Exception\ConflictException;
use Pagekit\Site\Controller\MenuApiController;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers the MenuApiController validation refactor (Assert constraints +
 * ValidatesRequestTrait) plus its Step 4 migration onto the injected
 * NodeRepository. The validation paths (same-id save, validation failures,
 * duplicate-id conflict) stay clear of the ORM, while the node-cascade paths
 * (index counts, rename and delete) drive the repository's where()->count() /
 * where()->update() chain through a mocked NodeRepository and QueryBuilder — so
 * no database is required. QueryBuilder proxies count()/update() through
 * __call(), so those verbs are stubbed/asserted on __call().
 *
 * The validator is wired with the real `validators` translation catalogue, so
 * failures surface the same human-readable messages as production.
 */
#[Group('integration')]
class MenuApiControllerTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $translator = new Translator('en_US');
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addResource(
            'php',
            __DIR__ . '/../../../../languages/en_US/validators.php',
            'en_US',
            'validators'
        );

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setTranslator($translator)
            ->setTranslationDomain('validators')
            ->getValidator();
    }

    public function testSaveActionWithValidMenuReturnsSuccess(): void
    {
        $siteConfig = new Config();

        // Same id and label-derived slug: no rename, so the ORM is never touched.
        $request = new Request();
        $request->request->set('menu', ['id' => 'main', 'label' => 'Main']);

        $result = $this->createController($siteConfig, $request)->saveAction();

        $this->assertSame('success', $result['message']);
        $this->assertSame(['id' => 'main', 'label' => 'Main'], $result['menu']);
        $this->assertSame(['id' => 'main', 'label' => 'Main'], $siteConfig->get('menus.main'));
    }

    public function testSaveActionTrimsIdAndLabelWhitespace(): void
    {
        // Re-saving an existing menu whose id arrives whitespace-padded: the
        // padded id must be trimmed to match the slug so the rename branch is
        // skipped (an untrimmed id would flag the entry as a duplicate and
        // orphan it). The stored label is trimmed too.
        $siteConfig = new Config(['menus' => ['main' => ['id' => 'main', 'label' => 'Main']]]);

        $request = new Request();
        $request->request->set('menu', ['id' => ' main ', 'label' => '  Main  ']);

        $result = $this->createController($siteConfig, $request)->saveAction();

        $this->assertSame('success', $result['message']);
        $this->assertSame(['id' => 'main', 'label' => 'Main'], $siteConfig->get('menus.main'));
        $this->assertFalse($siteConfig->has('menus. main '));
    }

    public function testSaveActionWithInvalidIdThrowsBadRequest(): void
    {
        $siteConfig = new Config();

        // A non-blank label that slugifies to an empty id: the derived
        // identifier must fail validation with a translated message instead of
        // the old bare exception.
        $request = new Request();
        $request->request->set('menu', ['label' => '!!!']);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Menu id is required.');

        $this->createController($siteConfig, $request)->saveAction();
    }

    public function testSaveActionWithDuplicateIdThrowsConflict(): void
    {
        $siteConfig = new Config(['menus' => ['main' => ['id' => 'main', 'label' => 'Existing']]]);

        // New menu whose label slugifies to an already-registered id.
        $request = new Request();
        $request->request->set('menu', ['label' => 'Main']);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Duplicate Menu Id.');

        $this->createController($siteConfig, $request)->saveAction();
    }

    public function testDeleteActionWithoutIdThrowsBadRequest(): void
    {
        $siteConfig = new Config();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Menu id is required.');

        $this->createController($siteConfig, new Request())->deleteAction(null);
    }

    public function testIndexActionCountsNodesPerMenuAndDropsEmptyTrash(): void
    {
        $menuManager = $this->createMock(MenuManager::class);
        $menuManager->method('all')->willReturn(['main' => ['id' => 'main', 'label' => 'Main']]);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('where')->willReturnMap([
            [['menu' => 'main'], $this->queryReturningCount(4)],
            [['menu' => 'trash'], $this->queryReturningCount(0)],
        ]);

        $result = $this->createController(new Config(), new Request(), $menuManager, $nodeRepository)->indexAction();

        $this->assertCount(1, $result, 'the empty trash bucket must be dropped');
        $this->assertSame('main', $result[0]['id']);
        $this->assertSame(4, $result[0]['count'], 'each menu count must come from nodeRepository->where(...)->count()');
    }

    public function testIndexActionRetainsTrashBucketWhenItHasNodes(): void
    {
        $menuManager = $this->createMock(MenuManager::class);
        $menuManager->method('all')->willReturn(['main' => ['id' => 'main', 'label' => 'Main']]);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->method('where')->willReturnMap([
            [['menu' => 'main'], $this->queryReturningCount(1)],
            [['menu' => 'trash'], $this->queryReturningCount(2)],
        ]);

        $result = $this->createController(new Config(), new Request(), $menuManager, $nodeRepository)->indexAction();

        $this->assertContains('trash', array_column($result, 'id'), 'a non-empty trash bucket must be kept');
    }

    public function testSaveActionRenamesMenuAndCascadesToNodes(): void
    {
        $siteConfig = new Config(['menus' => ['old-menu' => ['id' => 'old-menu', 'label' => 'Old']]]);

        $request = new Request();
        $request->request->set('menu', ['id' => 'old-menu', 'label' => 'New Label']);

        $query = $this->createMock(QueryBuilder::class);
        // The rename cascades to the affected nodes via where(...)->update(...).
        $query->expects($this->once())->method('__call')->with('update', [['menu' => 'new-label']])->willReturn(1);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())
            ->method('where')
            ->with(['menu = :old'], ['old' => 'old-menu'])
            ->willReturn($query);

        $result = $this->createController($siteConfig, $request, null, $nodeRepository)->saveAction();

        $this->assertSame('success', $result['message']);
        $this->assertSame(['id' => 'new-label', 'label' => 'New Label'], $siteConfig->get('menus.new-label'));
        $this->assertFalse($siteConfig->has('menus.old-menu'), 'the old menu entry must be removed on rename');
    }

    public function testDeleteActionMovesMenuNodesToTrash(): void
    {
        $siteConfig = new Config(['menus' => ['gone' => ['id' => 'gone', 'label' => 'Gone']]]);

        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())->method('__call')->with('update', [['menu' => 'trash', 'status' => 0]])->willReturn(2);

        $nodeRepository = $this->createMock(NodeRepository::class);
        $nodeRepository->expects($this->once())
            ->method('where')
            ->with(['menu = :id'], ['id' => 'gone'])
            ->willReturn($query);

        $result = $this->createController($siteConfig, new Request(), null, $nodeRepository)->deleteAction('gone');

        $this->assertSame('success', $result['message']);
        $this->assertFalse($siteConfig->has('menus.gone'), 'the deleted menu entry must be removed from config');
    }

    private function createController(
        Config $siteConfig,
        Request $request,
        ?MenuManager $menu = null,
        ?NodeRepository $nodeRepository = null,
    ): MenuApiController {
        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($siteConfig);

        return new MenuApiController(
            $config,
            $menu ?? $this->createMock(MenuManager::class),
            $request,
            new FilterManager(),
            $this->validator,
            $nodeRepository ?? $this->createMock(NodeRepository::class),
        );
    }

    /**
     * Builds a QueryBuilder double whose count() (proxied through __call)
     * returns the given value — the per-menu node count indexAction reads.
     *
     * @return QueryBuilder<Node>
     */
    private function queryReturningCount(int $count): QueryBuilder
    {
        /** @var QueryBuilder<Node>&MockObject $query */
        $query = $this->createMock(QueryBuilder::class);
        $query->method('__call')->with('count', [])->willReturn($count);

        return $query;
    }
}
