<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application;
use Pagekit\Application\Response;
use Pagekit\Config\ConfigManager;
use Pagekit\Installer\Package\PackageScripts;
use Pagekit\Migration\MigrationService;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Router;
use Pagekit\Session\MessageBag;
use Pagekit\System\SystemModule;
use Pagekit\User\Attribute\Access;

#[Access('system: software updates', admin: true)]
class MigrationController
{
    protected PackageScripts $scripts;

    public function __construct(
        private readonly SystemModule $system,
        private readonly ConfigManager $config,
        private readonly string $version,
        private readonly MessageBag $message,
        private readonly Response $response,
        private readonly Router $router,
        private readonly Application $app,
    ) {
        $this->scripts = new PackageScripts($this->system->path.'/scripts.php', $this->system->config('version'), $this->app);
    }

    /**
     * @return array<string, mixed>|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    #[Request(['redirect' => 'string'])]
    public function indexAction(?string $redirect = null): array|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $hasPendingMigrations = false;
        if ($this->app->has('migration')) {
            /** @var MigrationService $migrationService */
            $migrationService = $this->app->get('migration');
            $migrationStatus = $migrationService->status();
            $hasPendingMigrations = !($migrationStatus['success'] ?? false) || ($migrationStatus['has_pending'] ?? false);
        }

        if (!$this->scripts->hasUpdates() && !$hasPendingMigrations) {
            return $this->router->redirect($redirect ?: '@system');
        }

        return [
            '$view' => [
                'title' => __('Update Pagekit'),
                'name' => 'system/theme:views/migration.php',
                'layout' => false,
            ],
            'redirect' => $redirect,
        ];
    }

    #[Request(['redirect' => 'string'], csrf: true)]
    public function migrateAction(?string $redirect = null): \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $migrationResult = ['success' => true, 'executed' => 0];

        if ($this->app->has('migration')) {
            /** @var MigrationService $migrationService */
            $migrationService = $this->app->get('migration');

            $migrationResult = $migrationService->migrate();
            if (!$migrationResult['success']) {
                return $this->response->json([
                    'status' => false,
                    'message' => sprintf('Doctrine Migration failed: %s', $migrationResult['error'] ?? 'unknown error'),
                ], 500);
            }
        }

        if ($updates = $this->scripts->hasUpdates()) {
            try {
                $this->scripts->update();
            } catch (\Throwable $e) {
                return $this->response->json([
                    'status' => false,
                    'message' => sprintf('Script update failed: %s', $e->getMessage()),
                ], 500);
            }
            $message = __('Your Pagekit database has been updated successfully.');
        } else {
            $message = $migrationResult['executed'] > 0
                ? __('Your Pagekit database has been updated successfully.')
                : __('Your database is up to date.');
        }

        ($this->config)('system')->set('version', $this->version);

        if ($redirect) {
            $this->message->success($message);

            return $this->router->redirect($redirect);
        }

        return $this->response->json([
            'status' => (bool) ($updates || $migrationResult['executed'] > 0),
            'message' => $message,
        ]);
    }
}
