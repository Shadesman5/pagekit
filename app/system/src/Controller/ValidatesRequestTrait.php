<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Trait for validating entities using Symfony Validator.
 *
 * Classes using this trait MUST provide a `$validator` property
 * (typically via constructor injection with param name `validator`).
 */
trait ValidatesRequestTrait
{
    /**
     * Validate an entity using Symfony Validator.
     *
     * @param object $object The entity to validate
     * @param ValidatorInterface|null $validator Optional validator instance (falls back to $this->validator)
     * @param array<int, string>|null $groups Optional validation groups
     * @return JsonResponse|null Returns JsonResponse on validation failure, null on success
     */
    protected function validate(
        object $object,
        ?ValidatorInterface $validator = null,
        ?array $groups = null
    ): ?JsonResponse {
        $validator ??= $this->validator;

        $violations = $validator->validate($object, null, $groups);

        if (count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        return null;
    }

    /**
     * Validate an entity and throw BadRequestHttpException on failure.
     *
     * @param object $object The entity to validate
     * @param ValidatorInterface|null $validator Optional validator instance
     * @param array<int, string>|null $groups Optional validation groups
     */
    protected function validateOrFail(
        object $object,
        ?ValidatorInterface $validator = null,
        ?array $groups = null
    ): void {
        $validator ??= $this->validator;

        $violations = $validator->validate($object, null, $groups);

        if (count($violations) > 0) {
            $firstViolation = $violations[0];

            throw new BadRequestHttpException((string) $firstViolation->getMessage());
        }
    }

    /**
     * Create a JSON response for validation errors.
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

        $firstError = count($violations) > 0 ? $violations[0]->getMessage() : 'Validation failed';

        return new JsonResponse([
            'error' => true,
            'message' => $firstError,
            'errors' => $errors,
        ], 400);
    }
}
