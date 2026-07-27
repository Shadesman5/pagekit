<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Content\ContentHelper;
use Pagekit\Database\ORM\Repository;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\Page;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PageController
{
    /**
     * @param Repository<Page> $pageRepository
     */
    public function __construct(
        private readonly ContentHelper $content,
        private readonly Node $node,
        private readonly Repository $pageRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function indexAction(int $id = 0): array
    {
        if (!$page = $this->pageRepository->find($id)) {
            throw new NotFoundHttpException(__('Page not found.'));
        }

        $page->content = $this->content->applyPlugins($page->content ?? '', ['page' => $page, 'markdown' => $page->get('markdown')]);

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
