<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Database\ORM\Repository;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Site\Model\Page;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Access('site: manage site')]
class PageApiController
{
    /**
     * @param Repository<Page> $pageRepository
     */
    public function __construct(
        private readonly Repository $pageRepository,
    ) {
    }

    /**
     * @return array<int, Page>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        return array_values($this->pageRepository->findAll());
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): Page
    {
        $page = $this->pageRepository->find($id);
        if ($page === null) {
            throw new NotFoundHttpException(__('Page not found.'));
        }

        return $page;
    }
}
