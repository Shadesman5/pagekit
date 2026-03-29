<?php

declare(strict_types=1);

namespace Pagekit\Cache\Controller;

use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;

#[Access(admin: true)]
class CacheController
{
    public function __construct(
        private readonly mixed $request,
        private readonly mixed $module,
    ) {
    }

    #[Route('/clear', methods: ['POST'])]
    public function clearAction(): array
    {
        $caches = $this->request->request->all()['caches'] ?? [];
        if (empty($caches) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $caches = $json['caches'] ?? [];
        }

        $this->module->get('system/cache')->clearCache($caches);

        return ['message' => 'success'];
    }
}
