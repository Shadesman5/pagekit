<?php

declare(strict_types=1);

namespace Pagekit\System;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service provider for Symfony Validator integration.
 *
 * This provider sets up the Symfony Validator with PHP 8 Attribute support.
 * Part of the hybrid validation strategy (Step 1.13):
 * - Validation: Uses PHP 8 Attributes (#[Assert\...])
 * - ORM: Still uses Doctrine Annotations (until Step 1.14)
 *
 * @see https://symfony.com/doc/current/validation.html
 */
class ValidatorServiceProvider
{
    /**
     * Register the Symfony Validator service.
     *
     * @param \Pagekit\Application $app The Pagekit application container
     */
    public static function register($app): void
    {
        $app['validator'] = function ($app): ValidatorInterface { // TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)
            $builder = Validation::createValidatorBuilder();

            // CRITICAL: Enable PHP 8 Attribute support for Validation
            // This allows using #[Assert\NotBlank], #[Assert\Email], etc.
            $builder->enableAttributeMapping();

            // Register custom constraint validators
            // The UniqueValidator will be auto-discovered by Symfony's naming convention
            // (Constraint class + "Validator" suffix in the same namespace)

            return $builder->getValidator();
        };
    }
}
