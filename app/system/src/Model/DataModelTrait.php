<?php

declare(strict_types=1);

namespace Pagekit\System\Model;

use Pagekit\Database\ORM\Attribute as ORM;
use Pagekit\Util\Arr;

trait DataModelTrait
{
    #[ORM\Column(type: 'json')]
    public mixed $data = null;

    /**
     * Gets a data value.
     *
     * @param  string $key
     * @param  mixed  $default Genuinely unknown type — default may be any type as data values can be any scalar, array, or object.
     * @return mixed Genuinely unknown type — data values are user-supplied JSON-decoded content; any scalar, array, or null is valid.
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
