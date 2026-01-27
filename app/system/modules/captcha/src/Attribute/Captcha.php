<?php

declare(strict_types=1);

namespace Pagekit\Captcha\Attribute;

/**
 * Captcha attribute for defining captcha requirements on routes.
 *
 * Usage:
 *   #[Captcha(route: '@route/name')]       - Inject captcha for route
 *   #[Captcha(verify: true)]               - Verify captcha on this route
 *   #[Captcha(route: '@route', verify: true)] - Both inject and verify
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Captcha
{
    private ?string $route;
    private bool $verify;

    /**
     * @param string|null $route The route to inject captcha for
     * @param bool $verify Whether to verify captcha on this route
     */
    public function __construct(
        ?string $route = null,
        bool $verify = false
    ) {
        $this->route = $route;
        $this->verify = $verify;
    }

    /**
     * Gets the captcha route.
     */
    public function getRoute(): ?string
    {
        return $this->route;
    }

    /**
     * Gets verify option.
     */
    public function getVerify(): bool
    {
        return $this->verify;
    }
}
