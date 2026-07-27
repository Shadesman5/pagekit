<?php

declare(strict_types=1);

namespace Pagekit;

use Pagekit\Event\EventDispatcher;
use Pagekit\Module\ModuleManager;
use Symfony\Component\HttpFoundation\Request;

class Application extends Container
{
    protected bool $booted = false;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        parent::__construct($values);

        $this->set('app', $this);
        $this->set('events', fn () => new EventDispatcher());

        $this->set('module', fn () => new ModuleManager($this));
    }

    /**
     * Boots all modules.
     */
    public function boot(): void
    {
        if (!$this->booted) {

            $this->booted = true;
            $this->get('events')->trigger('boot', [$this]);

        }
    }

    /**
     * Handles the request.
     *
     * @param Request $request
     */
    public function run(?Request $request = null): void
    {
        if ($request === null) {
            $request = Request::createFromGlobals();
        }

        if (!$this->booted) {
            $this->boot();
        }

        $response = $this->get('kernel')->handle($request);
        $response->send();

        $this->get('kernel')->terminate($request, $response);
    }

    /**
     * Checks if running in the console.
     */
    public function inConsole(): bool
    {
        return PHP_SAPI == 'cli';
    }

}
