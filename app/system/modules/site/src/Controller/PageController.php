<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Site\Model\Page;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PageController
{
    public function __construct(
        private readonly mixed $content,
        private readonly mixed $node,
    ) {
    }

    public function indexAction($id = 0): array
    {
        if (!$page = Page::find($id)) {
            throw new NotFoundHttpException(__('Page not found.'));
        }

        $page->content = $this->content->applyPlugins($page->content, ['page' => $page, 'markdown' => $page->get('markdown')]);

        return [
            '$view' => [
                'title' => $page->title,
                'name' => 'system/site/page.php',
            ],
            'page' => $page,
            'node' => $this->node,
        ];
    }
}
