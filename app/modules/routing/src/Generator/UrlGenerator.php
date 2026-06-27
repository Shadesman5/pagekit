<?php

declare(strict_types=1);

namespace Pagekit\Routing\Generator;

use Symfony\Component\Routing\Generator\UrlGenerator as BaseUrlGenerator;

class UrlGenerator extends BaseUrlGenerator implements LinkReferenceType
{
    /**
     * {@inheritdoc}
     *
     * @param array<int, string>             $variables
     * @param array<string, mixed>           $defaults
     * @param array<string, string>          $requirements
     * @param array<int, array<int, mixed>>  $tokens
     * @param array<string, mixed>           $parameters
     * @param array<int, array<int, mixed>>  $hostTokens
     * @param array<int, string>             $requiredSchemes
     */
    protected function doGenerate(array $variables, array $defaults, array $requirements, array $tokens, array $parameters, string $name, int $referenceType, array $hostTokens, array $requiredSchemes = []): string
    {
        $link = $name;

        if ($params = array_intersect_key($parameters, array_flip(isset($defaults['_variables']) ? $defaults['_variables'] : $variables))) {

            $link .= '?'.http_build_query($params);

            if ($properties = $this->getRouteProperties($link)) {
                list($variables, $defaults, $requirements, $tokens, $hostTokens, $requiredSchemes) = $properties;
            }
        }

        if ($referenceType === LinkReferenceType::LINK_URL) {
            return $link;
        }

        return parent::doGenerate($variables, $defaults, $requirements, $tokens, $parameters, $name, $referenceType, $hostTokens, $requiredSchemes);
    }

    /**
     * Gets the properties of a route.
     *
     * @param  string $name
     * @return array<int, mixed>|null
     */
    public function getRouteProperties(string $name): ?array
    {
        if (!$route = $this->routes->get($name)) {
            return null;
        }

        $compiled = $route->compile();

        return [$compiled->getVariables(), $route->getDefaults(), $route->getRequirements(), $compiled->getTokens(), $compiled->getHostTokens(), $route->getSchemes()];
    }
}
