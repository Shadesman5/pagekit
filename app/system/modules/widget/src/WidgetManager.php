<?php

declare(strict_types=1);

namespace Pagekit\Widget;

use Pagekit\Application;
use Pagekit\Module\ModuleManager;

class WidgetManager extends ModuleManager
{
    public function __construct(Application $app)
    {
        parent::__construct($app);

        $this->defaults['class'] = 'Pagekit\Widget\Model\Type';
    }

    /**
     * @return mixed Genuinely unknown type — overrides ModuleManager::get(); widget types are registered dynamically; the returned value may be a Type instance or null.
     */
    public function get(string $name): mixed
    {
        $this->load(array_keys($this->registered));

        return parent::get($name);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->load(array_keys($this->registered));

        return parent::all();
    }
}
