<?php

declare(strict_types=1);

namespace Pagekit\System;

use Pagekit\System\Validator\Constraints\UniqueValidator;
use Symfony\Component\Validator\ContainerConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service provider for Symfony Validator integration.
 *
 * Sets up the Symfony Validator with PHP 8 Attribute mapping,
 * Translator-backed constraint messages via the 'validators' domain and
 * container-resolved constraint validators.
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
        // A constraint validator with dependencies is a container entry keyed
        // by its own class name — the id ContainerConstraintValidatorFactory
        // looks up before falling back to `new $class()` for the stateless
        // validators Symfony ships.
        $app->set(UniqueValidator::class, fn (\Pagekit\Application $app): UniqueValidator => new UniqueValidator($app->get('db')));

        $app->set('validator', function (\Pagekit\Application $app): ValidatorInterface {
            $builder = Validation::createValidatorBuilder();

            $builder->enableAttributeMapping();
            $builder->setConstraintValidatorFactory(new ContainerConstraintValidatorFactory($app));

            $builder->setTranslator($app->get('translator'));
            $builder->setTranslationDomain('validators');

            return $builder->getValidator();
        });
    }
}
