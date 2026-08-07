<?php

declare(strict_types=1);

namespace Pagekit\Application;

use Symfony\Component\HttpFoundation\Request;

/**
 * The reverse proxies whose X-Forwarded-* headers describe the real request.
 *
 * Behind a TLS-terminating proxy the connection Apache sees is plain HTTP on an
 * internal address. Unless the proxy is trusted, isSecure() reports false and
 * every absolute URL, redirect and secure-cookie decision is built from the
 * wrong scheme, host and port.
 */
final class TrustedProxies
{
    /**
     * Trusted proxy addresses or CIDR ranges, comma-separated. Symfony also
     * accepts the literal "REMOTE_ADDR" to trust whichever peer connected.
     */
    public const ENV_VAR = 'PAGEKIT_TRUSTED_PROXIES';

    /**
     * The headers a trusted proxy may speak for. X-Forwarded-Prefix is left out:
     * Pagekit is served from the root of its own host or from a subdirectory it
     * derives itself, never from a prefix a proxy strips.
     */
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Trusts the proxies named in the environment. Without them nothing is
     * trusted, which is what a directly exposed installation wants.
     */
    public static function configureFromEnvironment(): void
    {
        $value = getenv(self::ENV_VAR);

        if (!is_string($value) || ($proxies = self::parse($value)) === []) {
            return;
        }

        Request::setTrustedProxies($proxies, self::HEADERS);
    }

    /**
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        $proxies = array_map('trim', explode(',', $value));

        return array_values(array_filter($proxies, static fn (string $proxy): bool => $proxy !== ''));
    }
}
