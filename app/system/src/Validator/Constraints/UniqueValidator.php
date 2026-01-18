<?php

declare(strict_types=1);

namespace Pagekit\System\Validator\Constraints;

use Pagekit\Application as App;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validator for the Unique constraint.
 *
 * Checks if a value already exists in the database, excluding the current
 * entity when updating (based on ID).
 *
 * Uses Pagekit's static DB access since DI is not yet fully modernized for Validators.
 */
class UniqueValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Unique) {
            throw new UnexpectedTypeException($constraint, Unique::class);
        }

        // Skip validation for null or empty values (let NotBlank handle that)
        if (null === $value || '' === $value) {
            return;
        }

        // Get the database connection using Pagekit's Application
        $db = App::db();

        // Build the uniqueness check query
        // Using Pagekit's QueryBuilder which is DBAL 3.x compatible
        $queryBuilder = $db->createQueryBuilder();
        $queryBuilder
            ->select('COUNT(*)')
            ->from($constraint->table)
            ->where("{$constraint->column} = :value")
            ->setParameter('value', strtolower((string) $value));

        // Handle update context: exclude current record by ID
        // Access the object being validated to get its ID
        $object = $this->context->getObject();

        if ($object !== null && $constraint->idProperty !== null) {
            $idProperty = $constraint->idProperty;

            // Get the ID value using property access
            if (property_exists($object, $idProperty)) {
                $idValue = $object->$idProperty;

                // Only exclude if we have an ID (update scenario)
                if ($idValue !== null && $idValue > 0) {
                    $queryBuilder
                        ->andWhere('id <> :id')
                        ->setParameter('id', $idValue);
                }
            }
        }

        // Execute query and get count
        // DBAL 3.x: execute() returns Result, fetchOne() gets single value
        $result = $queryBuilder->execute();
        $count = (int) $result->fetchOne();

        if ($count > 0) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
