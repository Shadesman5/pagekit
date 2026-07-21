<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Application\UrlProvider;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use Pagekit\User\Model\User;
use Pagekit\View\Helper\Helper;

class MenuHelper extends Helper
{
    public function __construct(
        private readonly MenuManager $menus,
        private readonly User $user,
        private readonly Node $node,
        private readonly NodePresenter $nodePresenter,
        private readonly NodeRepository $nodes,
    ) {
    }

    /**
     * Set shortcut.
     *
     * @see render()
     *
     * @param array<string, mixed>|string|null $view
     * @param array<string, mixed>             $parameters
     */
    public function __invoke(string $name, array|string|null $view = null, array $parameters = []): ?string
    {
        if (!$name = $this->menus->find($name)) {
            return '';
        }

        return $this->render($name, $view, $parameters);
    }

    /**
     * Checks if the menu exists.
     *
     * @param  string $name
     */
    public function exists($name): bool
    {
        return (bool) $this->menus->find($name);
    }

    /**
     * Renders a menu.
     *
     * @param  string                            $name
     * @param  array<string, mixed>|string|null  $view
     * @param  array<string, mixed>              $parameters
     */
    public function render($name, $view = null, array $parameters = []): ?string
    {
        if (is_array($view)) {
            $parameters = $view;
            $view = false;
        }

        if (!$root = $this->getRoot($name, $parameters)) {
            return '';
        }

        if ($this->view === null) {
            throw new \LogicException('MenuHelper has not been registered with a View instance.');
        }

        return $this->view->render($view ?: 'system/site/menu.php', array_replace($parameters, compact('root')));
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'menu';
    }

    /**
     * @param  string                $menu
     * @param  array<string, mixed>  $parameters
     * @return Node|null
     */
    public function getRoot($menu, $parameters = []): ?Node
    {
        $parameters = array_replace([
            'start_level' => 1,
            'depth' => PHP_INT_MAX,
            'mode' => 'all',
        ], $parameters);

        $user = $this->user;
        $startLevel = (int) $parameters['start_level'] ?: 1;
        $maxDepth = $startLevel + ($parameters['depth'] ?: PHP_INT_MAX);

        $nodes = $this->nodes->findByMenu($menu, true);
        $nodes[0] = new Node(['path' => '/']);
        $nodes[0]->status = 1;
        $nodes[0]->parent_id = null;

        $node = $this->node;
        $path = $node->path;

        if (!isset($nodes[$node->id])) {
            foreach ($nodes as $node) {
                if ($this->nodePresenter->getUrl($node, UrlProvider::BASE_PATH) === $path) {
                    $path = $node->path;

                    break;
                }
            }
        }

        $path .= '/';

        $segments = explode('/', $path);
        $rootPath = count($segments) > $startLevel ? implode('/', array_slice($segments, 0, $startLevel + 1)).'/' : '/';

        foreach ($nodes as $node) {

            $depth = substr_count($node->path ?? '', '/');
            $parent = $node->parent_id !== null && isset($nodes[$node->parent_id]) ? $nodes[$node->parent_id] : null;

            $node->set('active', 0 === strpos($path, $node->path.'/'));
            $node->set('url', $this->nodePresenter->getUrl($node));

            if ($node->status !== 1
                || $depth >= $maxDepth
                || !$node->hasAccess($user)
                || $node->get('menu_hide')
                || !($parameters['mode'] == 'all'
                    || $node->get('active')
                    || 0 === strpos($node->path.'/', $rootPath)
                    || $depth === $startLevel)
            ) {
                $node->setParent();

                continue;
            }

            $node->setParent($parent);

            if ($node->get('active') && $depth === $startLevel - 1) {
                $root = $node;
            }

        }

        if (!isset($root)) {
            return null;
        }

        $root->setParent();

        return $root;
    }
}
