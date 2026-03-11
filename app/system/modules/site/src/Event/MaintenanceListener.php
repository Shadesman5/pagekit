<?php

namespace Pagekit\Site\Event;

use Pagekit\Application as App;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Module\Module;

class MaintenanceListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly App $app,
        private readonly Module $site,
    ) {}

    /**
     * Puts the page in maintenance mode.
     */
    public function onRequest($event, $request): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $user = $this->app->get('auth')->getUser();

        if ($this->site->config('maintenance.enabled') && !($this->app->get('isAdmin') || $request->attributes->get('_maintenance') || $user?->hasAccess('site: maintenance access') || $user?->hasAccess('system: access admin area'))) {

            $message = $this->site->config('maintenance.msg') ?: __("We'll be back soon.");
            $logo = $this->site->config('maintenance.logo') ?: 'app/system/assets/images/pagekit-logo-large-black.svg';
            $view = $this->app->get('view');
            $viewResponse = $view('system/theme:views/maintenance.php', compact('message', 'logo'));

            $request->attributes->set('_disable_debugbar', true);

            $types = $request->getAcceptableContentTypes();
            $response = $this->app->get('response');

            if (!$user?->isAuthenticated() && $request->isXMLHttpRequest()) {
                App::abort('401', 'Unauthorized'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            } elseif ('json' == $request->getFormat(array_shift($types))) {
                $viewResponse = $response->json($message, 503);
            } else {
                $viewResponse = $response->create($viewResponse, 503);
            }

            $event->setResponse($viewResponse);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'request' => ['onRequest', 10]
        ];
    }
}
