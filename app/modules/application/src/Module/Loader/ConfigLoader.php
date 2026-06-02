<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

class ConfigLoader implements LoaderInterface
{
    /** @var array<string, mixed> */
    protected array $values = [];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function load(mixed $module): mixed
    {
        if (is_array($module) && isset($this->values[$module['name']])) {
            $module = array_replace_recursive($module, [
                'config' => $this->values[$module['name']],
            ]);
        }

        return $module;
    }
}
