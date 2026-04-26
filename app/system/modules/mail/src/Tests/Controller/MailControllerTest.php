<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests\Controller;

use Pagekit\Mail\Controller\MailController;
use Pagekit\Mail\Mailer;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\NullTransport;

#[Group('integration')]
class MailControllerTest extends TestCase
{
    public function setUp(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
    }

    private function createController(Request $request, ?Mailer $mailer = null, ?Module $mailModule = null): MailController
    {
        $mailer = $mailer ?? new Mailer(new NullTransport());
        $module = $this->createMock(ModuleManager::class);
        if ($mailModule !== null) {
            $module->method('get')->willReturn($mailModule);
        }

        return new MailController($request, $mailer, $module);
    }

    public function testSmtpActionWithInvalidCredentials(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'host' => 'invalid-host.example.com',
            'port' => 25,
            'username' => 'invalid-user',
            'password' => 'invalid-password',
            'encryption' => null,
        ]);

        $controller = $this->createController($request);
        $result = $controller->smtpAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
    }

    #[Group('network')]
    public function testSmtpActionWithValidCredentials(): void
    {
        if (!($GLOBALS['email_smtp_host'] ?? false)) {
            $this->markTestSkipped('Email SMTP configuration not available');
        }

        $request = new Request();
        $request->request->set('option', [
            'host' => $GLOBALS['email_smtp_host'],
            'port' => (int)$GLOBALS['email_smtp_port'],
            'username' => $GLOBALS['email_smtp_user'],
            'password' => $GLOBALS['email_smtp_password'],
            'encryption' => $GLOBALS['email_smtp_encryption'],
        ]);

        $controller = $this->createController($request);
        $result = $controller->smtpAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
    }

    public function testSmtpActionReturnsCorrectStructure(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'host' => 'test-host',
            'port' => 587,
            'username' => 'test-user',
            'password' => 'test-pass',
            'encryption' => 'tls',
        ]);

        $controller = $this->createController($request);
        $result = $controller->smtpAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
        $this->assertNotEmpty($result['message']);
    }

    public function testSmtpActionWithMissingParameters(): void
    {
        $request = new Request();

        $controller = $this->createController($request);
        $result = $controller->smtpAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('required', $result['message']);
    }

    public function testSmtpActionWithPartialParameters(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'host' => 'partial-test.example.com',
            'port' => 25,
        ]);

        $controller = $this->createController($request);
        $result = $controller->smtpAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
    }

    public function testEmailActionWithoutConfiguration(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'from_address' => 'test@example.com',
        ]);

        $mailModule = $this->createMock(Module::class);
        $mailModule->method('config')->willReturn([
            'from_address' => 'test@example.com',
            'from_name' => null,
        ]);

        $controller = $this->createController($request, null, $mailModule);
        $result = $controller->emailAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
    }

    #[Group('network')]
    public function testEmailActionWithConfiguration(): void
    {
        if (!($GLOBALS['email_address'] ?? false)) {
            $this->markTestSkipped('Email test configuration not available');
        }

        $request = new Request();
        $request->request->set('option', [
            'from_address' => $GLOBALS['email_address'],
        ]);

        $mailModule = $this->createMock(Module::class);
        $mailModule->method('config')->willReturn([
            'from_address' => $GLOBALS['email_address'],
            'from_name' => null,
        ]);

        $controller = $this->createController($request, null, $mailModule);
        $result = $controller->emailAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
    }

    public function testEmailActionReturnsCorrectStructure(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'from_address' => 'test@example.com',
        ]);

        $mailModule = $this->createMock(Module::class);
        $mailModule->method('config')->willReturn([
            'from_address' => 'test@example.com',
            'from_name' => null,
        ]);

        $controller = $this->createController($request, null, $mailModule);
        $result = $controller->emailAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
    }

    public function testSmtpActionWithInvalidJson(): void
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], 'invalid json { not valid }');

        $controller = $this->createController($request);
        $result = $controller->smtpAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
    }

    public function testEmailActionWithInvalidJson(): void
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], 'invalid json { not valid }');

        $mailModule = $this->createMock(Module::class);
        $mailModule->method('config')->willReturn([
            'from_address' => 'test@example.com',
            'from_name' => null,
        ]);

        $controller = $this->createController($request, null, $mailModule);
        $result = $controller->emailAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
    }
}
