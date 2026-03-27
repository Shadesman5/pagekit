<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Validator;

use Pagekit\Intl\Loader\PhpFileLoader;
use Pagekit\System\Controller\ValidatesRequestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Integration test: Symfony Validator + Translator with 'validators' domain (Step 2.0.2).
 *
 * Verifies that constraint violation messages are resolved through the
 * Translator catalogue rather than returned as raw message keys.
 */
class ValidatorTranslatorIntegrationTest extends TestCase
{
    private ValidatorInterface $validator;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator('en_US');
        $this->translator->addLoader('php', new PhpFileLoader());
        $this->translator->addResource(
            'php',
            __DIR__ . '/../../../app/system/languages/en_US/validators.php',
            'en_US',
            'validators'
        );

        $builder = Validation::createValidatorBuilder();
        $builder->enableAttributeMapping();
        $builder->setTranslator($this->translator);
        $builder->setTranslationDomain('validators');

        $this->validator = $builder->getValidator();
    }

    // ------------------------------------------------------------------
    // (a) Validator returns translated messages, not raw keys
    // ------------------------------------------------------------------

    public function testViolationMessageIsTranslatedNotRawKey(): void
    {
        $entity = new ValidatorTestEntity();

        $violations = $this->validator->validate($entity);

        $this->assertGreaterThan(0, count($violations));

        $messages = $this->extractMessages($violations);

        $this->assertContains('This field is required.', $messages);
        $this->assertNotContains('validation.required', $messages);
    }

    public function testLengthConstraintTranslatesWithParameters(): void
    {
        $entity = new ValidatorTestEntity();
        $entity->title = 'ab';

        $violations = $this->validator->validate($entity);

        $messages = $this->extractMessages($violations);

        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('validation.', $message);
        }

        $lengthMessages = array_filter(
            $messages,
            fn(string $m): bool => str_contains($m, 'characters')
        );
        $this->assertNotEmpty($lengthMessages, 'Expected a length violation with interpolated limit');
    }

    public function testValidEntityProducesNoViolations(): void
    {
        $entity = new ValidatorTestEntity();
        $entity->title = 'A valid title';
        $entity->email = 'test@example.com';

        $violations = $this->validator->validate($entity);

        $this->assertCount(0, $violations);
    }

    // ------------------------------------------------------------------
    // (b) validationErrorResponse() contains human-readable messages
    // ------------------------------------------------------------------

    public function testValidationErrorResponseContainsHumanReadableMessages(): void
    {
        $entity = new ValidatorTestEntity();

        $violations = $this->validator->validate($entity);
        $this->assertGreaterThan(0, count($violations));

        $controller = new class {
            use ValidatesRequestTrait;

            public ValidatorInterface $validator;

            public function getErrorResponse(ConstraintViolationListInterface $v): JsonResponse
            {
                return $this->validationErrorResponse($v);
            }
        };

        $response = $controller->getErrorResponse($violations);

        $this->assertSame(400, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);

        $this->assertTrue($payload['error']);
        $this->assertNotEmpty($payload['message']);
        $this->assertStringNotContainsString('validation.', $payload['message']);

        foreach ($payload['errors'] as $field => $fieldErrors) {
            foreach ($fieldErrors as $msg) {
                $this->assertStringNotContainsString(
                    'validation.',
                    $msg,
                    "Field '{$field}' returned raw key: {$msg}"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // (c) Locale fallback to en_US
    // ------------------------------------------------------------------

    public function testLocaleFallbackToEnUs(): void
    {
        $translator = new Translator('de_DE');
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addResource(
            'php',
            __DIR__ . '/../../../app/system/languages/en_US/validators.php',
            'en_US',
            'validators'
        );
        $translator->setFallbackLocales(['en_US']);

        $builder = Validation::createValidatorBuilder();
        $builder->enableAttributeMapping();
        $builder->setTranslator($translator);
        $builder->setTranslationDomain('validators');

        $validator = $builder->getValidator();

        $entity = new ValidatorTestEntity();
        $violations = $validator->validate($entity);

        $this->assertGreaterThan(0, count($violations));

        $messages = $this->extractMessages($violations);

        $this->assertContains('This field is required.', $messages);
        $this->assertNotContains('validation.required', $messages);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function extractMessages(ConstraintViolationListInterface $violations): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getMessage();
        }
        return $messages;
    }
}

/**
 * Minimal entity used only by these tests.
 * References keys from app/system/languages/en_US/validators.php.
 */
class ValidatorTestEntity
{
    #[Assert\NotBlank(message: 'validation.required')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'validation.min_length',
        maxMessage: 'validation.max_length'
    )]
    public ?string $title = null;

    #[Assert\Email(message: 'validation.email')]
    public ?string $email = '';
}
