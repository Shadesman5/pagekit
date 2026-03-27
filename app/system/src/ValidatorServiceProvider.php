<?php

declare(strict_types=1);

namespace Pagekit\System;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service provider for Symfony Validator integration (Step 2.0.2).
 *
 * Sets up the Symfony Validator with PHP 8 Attribute mapping and
 * Translator-backed constraint messages via the 'validators' domain.
 *
 * @see https://symfony.com/doc/current/validation.html
 */
class ValidatorServiceProvider
{
    /**
     * Register the Symfony Validator service.
     *
     * Resolves the translator lazily inside the factory closure,
     * so boot-order with IntlModule is not an issue.
     *
     * @param \Pagekit\Application $app The Pagekit application container
     */
    public static function register(\Pagekit\Application $app): void
    {
        $app->set('validator', function ($app): ValidatorInterface {
            $builder = Validation::createValidatorBuilder();

            $builder->enableAttributeMapping();

            $builder->setTranslator($app->get('translator'));
            $builder->setTranslationDomain('validators');

            return $builder->getValidator();
        });
    }
}
