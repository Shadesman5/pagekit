<?php

declare(strict_types=1);

namespace Pagekit\System\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validator for the Unique constraint.
 *
 * Checks if a value already exists in the database, excluding the current
 * entity when updating (based on ID).
 *
 * Uses a static service locator for `db` because Symfony's default
 * ConstraintValidatorFactory instantiates validators via `new $class()`.
 */
class UniqueValidator extends ConstraintValidator
{
    private static mixed $db = null;

    public static function setDb(mixed $db): void
    {
        self::$db = $db;
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Unique) {
            throw new UnexpectedTypeException($constraint, Unique::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (self::$db === null) {
            throw new \RuntimeException('UniqueValidator: db service not initialized. Was ValidatorServiceProvider booted?');
        }

        $db = self::$db;

        $lowerValue = strtolower((string) $value);
        $whereConditions = ["LOWER({$constraint->column}) = :value"];
        $whereParams = ['value' => $lowerValue];

        $object = $this->context->getObject();

        if ($object !== null && $constraint->idProperty !== null) {
            $idProperty = $constraint->idProperty;

            if (property_exists($object, $idProperty)) {
                $idValue = $object->$idProperty;

                if ($idValue !== null && $idValue > 0) {
                    $whereConditions[] = "id <> :id";
                    $whereParams['id'] = $idValue;
                }
            }
        }

        $queryBuilder = $db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($constraint->table)
            ->where($whereConditions, $whereParams);

        $result = $queryBuilder->executeQuery();
        $count = (int) $result->fetchOne();

        if ($count > 0) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
