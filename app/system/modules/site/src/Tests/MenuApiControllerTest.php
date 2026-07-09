<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filter\FilterManager;
use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\Kernel\Exception\ConflictException;
use Pagekit\Site\Controller\MenuApiController;
use Pagekit\Site\MenuManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers the MenuApiController validation refactor (Assert constraints +
 * ValidatesRequestTrait). Exercises the paths that stay clear of the ORM:
 * a same-id save, the validation failures, and the duplicate-id conflict —
 * so no database connection is required.
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

    private function createController(Config $siteConfig, Request $request): MenuApiController
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($siteConfig);

        return new MenuApiController(
            $config,
            $this->createMock(MenuManager::class),
            $request,
            new FilterManager(),
            $this->validator,
        );
    }
}
