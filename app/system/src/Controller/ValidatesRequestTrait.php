<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application as App;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Trait for validating entities using Symfony Validator.
 *
 * This trait provides a standardized way to validate entities in controllers
 * and return consistent JSON error responses for validation failures.
 *
 * Usage:
 * ```php
 * class MyController
 * {
 *     use ValidatesRequestTrait;
 *
 *     public function saveAction()
 *     {
 *         $entity = new MyEntity();
 *         // ... populate entity ...
 *
 *         if ($errorResponse = $this->validate($entity)) {
 *             return $errorResponse;
 *         }
 *
 *         // Validation passed, continue with save
 *     }
 * }
 * ```
 */
trait ValidatesRequestTrait
{
    /**
     * Validate an entity using Symfony Validator.
     *
     * @param object $object The entity to validate
     * @param ValidatorInterface|null $validator Optional validator instance (uses app container if null)
     * @param array|null $groups Optional validation groups
     * @return JsonResponse|null Returns JsonResponse on validation failure, null on success
     */
    protected function validate(
        object $object,
        ?ValidatorInterface $validator = null,
        ?array $groups = null
    ): ?JsonResponse {
        if ($validator === null) {
            $validator = App::getInstance()['validator']; // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        }

        $violations = $validator->validate($object, null, $groups);

        if (count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        return null; // Validation passed
    }

    /**
     * Validate an entity and abort with HTTP 400 on failure.
     *
     * This method calls App::abort(400, ...) which correctly returns HTTP 400 Bad Request
     * instead of HTTP 500 Internal Server Error.
     *
     * @param object $object The entity to validate
     * @param ValidatorInterface|null $validator Optional validator instance
     * @param array|null $groups Optional validation groups
     */
    protected function validateOrFail(
        object $object,
        ?ValidatorInterface $validator = null,
        ?array $groups = null
    ): void {
        if ($validator === null) {
            $validator = App::getInstance()['validator']; // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        }

        $violations = $validator->validate($object, null, $groups);

        if (count($violations) > 0) {
            $firstViolation = $violations[0];
            // Use App::abort() to correctly return HTTP 400 Bad Request
            App::abort(400, $firstViolation->getMessage()); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }
    }

    /**
     * Create a JSON response for validation errors.
     *
     * Returns a standardized error format compatible with Vue.js frontend:
     * {
     *     "error": true,
     *     "message": "Validation failed",
     *     "errors": {
     *         "propertyName": ["Error message 1", "Error message 2"],
     *         "anotherProperty": ["Error message"]
     *     }
     * }
     *
     * @param ConstraintViolationListInterface $violations The validation violations
     * @return JsonResponse The error response with 400 status code
     */
    protected function validationErrorResponse(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $propertyPath = $violation->getPropertyPath();
            $message = $violation->getMessage();

            if (!isset($errors[$propertyPath])) {
                $errors[$propertyPath] = [];
            }

            $errors[$propertyPath][] = $message;
        }

        // Get first error message for the main message field
        $firstError = count($violations) > 0 ? $violations[0]->getMessage() : 'Validation failed';

        return new JsonResponse([
            'error' => true,
            'message' => $firstError,
            'errors' => $errors
        ], 400);
    }
}
