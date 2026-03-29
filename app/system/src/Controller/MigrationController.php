<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Installer\Package\PackageScripts;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;

#[Access('system: software updates', admin: true)]
class MigrationController
{
    protected PackageScripts $scripts;

    public function __construct(
        private readonly mixed $system,
        private readonly mixed $config,
        private readonly mixed $version,
        private readonly mixed $message,
        private readonly mixed $response,
        private readonly mixed $router,
        private readonly mixed $app,
    ) {
        $this->scripts = new PackageScripts($this->system->path.'/scripts.php', $this->system->config('version'), $this->app);
    }

    #[Request(['redirect' => 'string'])]
    public function indexAction($redirect = null)
    {
        if (!$this->scripts->hasUpdates()) {
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
    public function migrateAction($redirect = null)
    {
        if ($updates = $this->scripts->hasUpdates()) {
            $this->scripts->update();
            $message = __('Your Pagekit database has been updated successfully.');
        } else {
            $message = __('Your database is up to date.');
        }

        ($this->config)('system')->set('version', $this->version);

        if ($redirect) {
            $this->message->success($message);

            return $this->router->redirect($redirect);
        }

        return $this->response->json(['status' => (bool) $updates, 'message' => $message]);
    }
}
