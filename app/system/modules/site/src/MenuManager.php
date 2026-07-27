<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Config\Config;

class MenuManager implements \JsonSerializable
{
    /** @var array<string, array{name: string, label: string}> */
    protected ?array $positions = [];
    /** @var array<string, array<string, mixed>> */
    protected array $menus;
    protected Config $config;

    /**
     * @param array<string, array<string, mixed>> $menus
     */
    public function __construct(Config $config, array $menus = [])
    {
        $this->config = $config;
        $this->menus = $menus;
    }

    /**
     * Get shortcut.
     *
     * @see get()
     *
     * @return array<string, mixed>|null
     */
    public function __invoke(string $id): ?array
    {
        return $this->get($id);
    }

    /**
     * Gets menu by id.
     *
     * @param  string $id
     * @return array<string, mixed>|null
     */
    public function get($id): ?array
    {
        $menus = $this->all();

        return isset($menus[$id]) ? $menus[$id] : null;
    }

    /**
     * Gets menus.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $menus = $this->menus;

        foreach ($menus as $id => &$menu) {
            $menu['positions'] = array_keys($this->config->get('_menus', []), $id);
        }

        uasort($menus, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $menus + ['' => ['id' => '', 'label' => __('Not Linked'), 'fixed' => true]];
    }

    /**
     * Registers a menu position.
     *
     * @param string $name
     * @param string $label
     */
    public function register($name, $label): void
    {
        $this->positions[$name] = compact('name', 'label');
    }

    /**
     * Gets the menu positions.
     *
     * @return array<string, array{name: string, label: string}>|null
     */
    public function getPositions(): ?array
    {
        return $this->positions;
    }

    /**
     * Finds an assigned menu by position.
     *
     * @param  string $position
     */
    public function find($position): ?string
    {
        return $this->config->get("_menus.{$position}");
    }

    /**
     * Assigns a menu to menu positions.
     *
     * @param string             $id
     * @param array<int, string> $positions
     */
    public function assign($id, array $positions): void
    {
        $menus = $this->config->get('_menus', []);
        $menus = array_diff($menus, [$id]);

        foreach ($positions as $position) {
            $menus[$position] = $id;
        }

        $this->config->set('_menus', $menus);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->all();
    }
}
