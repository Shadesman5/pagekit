<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application as App;
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
    ) {
        $this->scripts = new PackageScripts($this->system->path.'/scripts.php', $this->system->config('version'));
    }

    #[Request(['redirect' => 'string'])]
    public function indexAction($redirect = null)
    {
        if (!$this->scripts->hasUpdates()) {
            return App::redirect($redirect ?: '@system'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        return [
            '$view' => [
                'title' => __('Update Pagekit'),
                'name' => 'system/theme:views/migration.php',
                'layout' => false
            ],
            'redirect' => $redirect
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
            return App::redirect($redirect); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        return $this->response->json(compact('status', 'message'));
    }
}
