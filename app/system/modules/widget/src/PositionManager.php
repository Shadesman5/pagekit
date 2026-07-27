<?php

declare(strict_types=1);

namespace Pagekit\Widget;

use Pagekit\Config\Config;

class PositionManager implements \JsonSerializable
{
    /** @var array<string, array<string, mixed>> */
    protected array $positions = [];
    protected Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Get shortcut.
     *
     * @see get()
     *
     * @return array<string, mixed>|null
     */
    public function __invoke(string $name): ?array
    {
        return $this->get($name);
    }

    /**
     * Gets position by name.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $positions = $this->all();

        return $positions[$name] ?? null;
    }

    /**
     * Gets menus.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        array_walk($this->positions, function (&$position, $name): void {
            $position['assigned'] = $this->config->get("_positions.$name", []);
        });

        return $this->positions;
    }

    /**
     * Registers a position.
     */
    public function register(string $name, string $label): void
    {
        $this->positions[$name] = compact('name', 'label');
    }

    /**
     * Finds a theme position by widget id.
     */
    public function find(int $id): string
    {
        foreach ($this->all() as $name => $position) {
            if (in_array($id, $position['assigned'])) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Assigns widgets to a theme position.
     *
     * @param array<int, int>|int $id
     */
    public function assign(string $position, array|int $id): void
    {
        $positions = $this->config->get('_positions', []);

        if (!is_array($id) && $position === $this->find($id)) {
            return;
        }

        foreach ($positions as $name => $assigned) {
            $positions[$name] = array_values(array_diff($assigned, (array) $id));
        }

        if (is_array($id)) {
            $positions[$position] = array_values(array_unique($id));
        } else {
            $positions[$position][] = $id;
        }

        $this->config->set('_positions', $positions);
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
