<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use Pagekit\Routing\Attribute\Route;
use Pagekit\Site\Model\Page;
use Pagekit\User\Attribute\Access;

#[Access('site: manage site')]
class PageApiController
{
    /**
     * @return array<int, Page>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        return array_values(Page::findAll());
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAction(int $id): Page
    {
        return Page::find($id);
    }
}
