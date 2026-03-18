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
 * TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
 * UniqueValidator is framework-instantiated by Symfony's ConstraintValidatorFactory — cannot use constructor injection.
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

        $db = App::getInstance()->get('db'); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e

        // Build the uniqueness check query with case-insensitive comparison
        // Using SQL LOWER() on both column and value for case-insensitive matching
        // This matches the behavior of the old User::validate() method which used
        // "LOWER(username) = :username" to prevent case-variant duplicates
        $lowerValue = strtolower((string) $value);
        $whereConditions = ["LOWER({$constraint->column}) = :value"];
        $whereParams = ['value' => $lowerValue];

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
                    $whereConditions[] = "id <> :id";
                    $whereParams['id'] = $idValue;
                }
            }
        }

        // Build query with all conditions combined
        $queryBuilder = $db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($constraint->table)
            ->where($whereConditions, $whereParams);

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
