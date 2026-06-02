<?php

declare(strict_types=1);

namespace Pagekit\Application;

use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Router;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;

class UrlProvider
{
    /**
     * Generates a path relative to the executed script, e.g. "/dir/file".
     */
    public const BASE_PATH = 'base';

    protected \Pagekit\Routing\Router $router;

    protected Filesystem $file;

    protected Locator $locator;

    /**
     * Constructor.
     *
     * @param Router $router
     */
    public function __construct(RouterInterface $router, Filesystem $file, Locator $locator)
    {
        $this->router = $router;
        $this->file = $file;
        $this->locator = $locator;
    }

    /**
     * Get shortcut.
     *
     * @see get()
     *
     * @param array<string, mixed> $parameters
     */
    public function __invoke(?string $path = '', array $parameters = [], int|string $referenceType = UrlGenerator::ABSOLUTE_PATH): string|false
    {
        return $this->get($path, $parameters, $referenceType);
    }

    /**
     * Gets the base path for the current request.
     *
     * @param  mixed $referenceType
     */
    public function base($referenceType = UrlGenerator::ABSOLUTE_PATH): string
    {
        $request = $this->router->getRequest();
        $url = $request->getBasePath();

        if ($referenceType === UrlGenerator::ABSOLUTE_URL) {
            $url = $request->getSchemeAndHttpHost().$url;
        } elseif ($referenceType === self::BASE_PATH) {
            $url = '';
        }

        return $url;
    }

    /**
     * Gets the URL for the current request.
     *
     * @param  mixed $referenceType
     */
    public function current($referenceType = UrlGenerator::ABSOLUTE_PATH): string
    {
        $request = $this->router->getRequest();

        $url = $request->getBaseUrl();

        if ($referenceType === UrlGenerator::ABSOLUTE_URL) {
            $url = $request->getSchemeAndHttpHost().$url;
        }

        if ($qs = $request->getQueryString()) {
            $qs = '?'.$qs;
        }

        return $url.$request->getPathInfo().$qs;
    }

    /**
     * Gets the URL for the previous request.
     */
    public function previous(): ?string
    {
        return $this->router->getRequest()->headers->get('referer');
    }

    /**
     * Gets the URL appending the URI to the base URI.
     *
     * @param array<string, mixed> $parameters
     */
    public function get(?string $path = '', array $parameters = [], int|string $referenceType = UrlGenerator::ABSOLUTE_PATH): string|false
    {
        $path ??= '';

        if (0 === strpos($path, '@')) {
            return $this->getRoute($path, $parameters, $referenceType);
        }

        $path = $this->parseQuery($path, $parameters);

        if (filter_var($path, FILTER_VALIDATE_URL) !== false) {
            return $path;
        }

        return $this->base($referenceType).'/'.ltrim($path, '/');
    }

    /**
     * Gets the URL to a named route.
     * Alias: route() for backward compatibility and clearer API.
     *
     * @param  string $name
     * @param  mixed  $parameters
     * @param  mixed  $referenceType
     * @return string|false
     */
    public function getRoute($name, $parameters = [], $referenceType = UrlGenerator::ABSOLUTE_PATH)
    {
        try {

            $type = $referenceType === self::BASE_PATH ? UrlGenerator::ABSOLUTE_PATH : $referenceType;
            if (!is_int($type)) {
                $type = UrlGenerator::ABSOLUTE_PATH;
            }
            $url = $this->router->generate($name, $parameters, $type);

            if ($referenceType === self::BASE_PATH) {
                $url = substr($url, strlen($this->router->getRequest()->getBaseUrl()));
            }

            return $url;

        } catch (RouteNotFoundException $e) {
        } catch (MissingMandatoryParametersException $e) {
        } catch (InvalidParameterException $e) {
        }

        return false;
    }

    /**
     * Alias for getRoute(). Generates URL to a named route.
     *
     * @param array<string, mixed> $parameters
     * @return string|false
     */
    public function route(string $name, array $parameters = [], int|string $referenceType = UrlGenerator::ABSOLUTE_PATH)
    {
        return $this->getRoute($name, $parameters, $referenceType);
    }

    /**
     * Gets the URL to a path resource.
     *
     * @param  string $path
     * @param  mixed  $parameters
     * @param  mixed  $referenceType
     */
    public function getStatic($path, $parameters = [], $referenceType = UrlGenerator::ABSOLUTE_PATH): string
    {
        $url = $this->file->getUrl($this->locator->get($path) ?: $path, $referenceType === self::BASE_PATH ? UrlGenerator::ABSOLUTE_PATH : $referenceType);

        if (!is_string($url)) {
            $url = '';
        }

        if ($referenceType === self::BASE_PATH) {
            $url = substr($url, strlen($this->router->getRequest()->getBasePath()));
        }

        return $this->parseQuery($url, $parameters);
    }

    /**
     * Parses query parameters into a URL.
     *
     * @param array<string, mixed> $parameters
     */
    protected function parseQuery(string $url, array $parameters = []): string
    {
        if (false !== ($queryPos = strpos($url, '?'))) {
            $query = substr($url, $queryPos + 1);
            $url = substr($url, 0, $queryPos);
            if ($query !== '') {
                parse_str($query, $params);
                $parameters = array_replace($parameters, $params);
            }
        }

        if ($query = http_build_query($parameters, '', '&')) {
            $url .= '?'.$query;
        }

        return $url;
    }
}
