<?php

declare(strict_types=1);

namespace Pagekit\System\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Util\Arr;

trait DataModelTrait
{
    #[ORM\Column(type: 'json_array')]
    public mixed $data = null;

    /**
     * Gets a data value.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get((array) $this->data, $key, $default);
    }

    /**
     * Sets a data value.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set(string $key, mixed $value): void
    {
        if (null === $this->data) {
            $this->data = [];
        }

        Arr::set($this->data, $key, $value);
    }
}
