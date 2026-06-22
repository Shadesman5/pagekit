<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Routing\Attribute\Route;
use Pagekit\Site\Model\Page;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access('site: manage site')]
class PageApiController
{
    /**
     * @return array<int, Page>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $pages = [];
        foreach (Page::findAll() as $entity) {
            if (!$entity instanceof Page) {
                throw new \LogicException(sprintf(
                    'Page::findAll() returned %s, expected %s',
                    get_class($entity),
                    Page::class
                ));
            }
            $pages[] = $entity;
        }

        return $pages;
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): Page
    {
        $page = Page::find($id);
        if ($page === null) {
            throw new NotFoundHttpException(__('Page not found.'));
        }

        return $page;
    }
}
