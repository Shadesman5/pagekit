<?php

declare(strict_types=1);

namespace Pagekit\View\Helper;

use Pagekit\View\View;

class SectionHelper extends Helper
{
    /** @var array<string, string> */
    protected array $sections = [];

    /** @var array<int, string> */
    protected array $openSections = [];

    /**
     * {@inheritdoc}
     */
    public function register(View $view): void
    {
        parent::register($view);

        $view->on('render', function ($event) {
            if ($this->exists($name = $event->getTemplate())) {
                $event->setResult($this->get($name));
            }
        }, 10);
    }

    /**
     * Set shortcut.
     *
     * @see set()
     */
    public function __invoke(string $name, string $content): void
    {
        $this->set($name, $content);
    }

    /**
     * Gets a section.
     */
    public function get(string $name): string
    {
        return isset($this->sections[$name]) ? $this->sections[$name] : '';
    }

    /**
     * Sets a section value.
     */
    public function set(string $name, string $content): void
    {
        $this->sections[$name] = $content;
    }

    /**
     * Checks if the section exists.
     */
    public function exists(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Starts a new section.
     *
     * @throws \InvalidArgumentException
     */
    public function start(string $name): void
    {
        if (in_array($name, $this->openSections)) {
            throw new \InvalidArgumentException(sprintf('A section named "%s" is already started.', $name));
        }

        $this->openSections[] = $name;
        $this->sections[$name] = '';

        ob_start();
        ob_implicit_flush(0);
    }

    /**
     * Stops a section.
     *
     * @throws \LogicException
     */
    public function stop(bool $show = false): void
    {
        if (!$this->openSections) {
            throw new \LogicException('No section started.');
        }

        $name = array_pop($this->openSections);

        $content = ob_get_clean();
        $this->sections[$name] = $content === false ? '' : $content;

        if ($show) {
            echo $this->view->render($name);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'section';
    }
}
