<?php

declare(strict_types=1);

namespace Pagekit\Site;

use Pagekit\Application\UrlProvider;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Site\Model\Node;
use Pagekit\User\Model\User;

/**
 * Presentation layer for {@see Node} entities.
 *
 * Constructor-injects the URL provider and the current user so the enriched
 * `url`/`accessible` output no longer depends on a static service-locator
 * reach-through. The entity stays a plain ORM model; presentation concerns live
 * here.
 */
final class NodePresenter
{
    public function __construct(
        private readonly UrlProvider $url,
        private readonly User $user,
    ) {
    }

    public function getUrl(Node $node, int|string $referenceType = UrlGenerator::ABSOLUTE_PATH): string|false
    {
        return $this->url->get($node->link, [], $referenceType);
    }

    public function isAccessible(Node $node, ?User $user = null): bool
    {
        return (bool) ($node->status && $node->hasAccess($user ?? $this->user));
    }

    /**
     * Serializes the node with the enriched `url` and `accessible` fields,
     * reproducing the shape the entity's former jsonSerialize() emitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(Node $node): array
    {
        return $node->toArray([
            'url' => $this->getUrl($node, UrlProvider::BASE_PATH),
            'accessible' => $this->isAccessible($node),
        ]);
    }
}
