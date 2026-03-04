<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use Pagekit\Application as App;
use Pagekit\Site\Model\Page;
use function Pagekit\__;

class PageController
{
    public function __construct(
        private readonly mixed $content,
        private readonly mixed $node,
    ) {}

    public function indexAction($id = 0): array
    {
        if (!$page = Page::find($id)) {
            App::abort(404, __('Page not found.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        $page->content = $this->content->applyPlugins($page->content, ['page' => $page, 'markdown' => $page->get('markdown')]);

        return [
            '$view' => [
                'title' => $page->title,
                'name'  => 'system/site/page.php'
            ],
            'page' => $page,
            'node' => $this->node
        ];
    }
}
