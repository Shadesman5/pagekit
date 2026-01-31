<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Pagekit\Mail\Controller\MailController;
use Pagekit\Mail\Mailer;
use Pagekit\Module\Module;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\NullTransport;

/**
 * @group integration
 */
class MailControllerTest extends TestCase
{
    protected ?MailController $controller = null;
    
    public function setUp(): void
    {
        // Define translation function if not available
        if (!function_exists('Pagekit\__')) {
            eval('namespace Pagekit; function __($message, $args = []) { return strtr($message, $args); }');
        }
        
        $this->controller = new MailController();
    }

    public function testSmtpActionWithInvalidCredentials(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'host' => 'invalid-host.example.com',
            'port' => 25,
            'username' => 'invalid-user',
            'password' => 'invalid-password',
            'encryption' => null
        ]);

        $mailer = new Mailer(new NullTransport());
        $result = $this->controller->smtpAction($request, $mailer);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
    }

    /**
     * @group network
     */
    public function testSmtpActionWithValidCredentials(): void
    {
        // Skip this test if email configuration is not available
        if (!($GLOBALS['email_smtp_host'] ?? false)) {
            $this->markTestSkipped('Email SMTP configuration not available');
        }

        $request = new Request();
            $request->request->set('option', [
            'host' => $GLOBALS['email_smtp_host'],
            'port' => (int)$GLOBALS['email_smtp_port'],
            'username' => $GLOBALS['email_smtp_user'],
            'password' => $GLOBALS['email_smtp_password'],
            'encryption' => $GLOBALS['email_smtp_encryption']
        ]);

        // Note: For real SMTP tests, we'd need a real mailer, but for structure testing we can use NullTransport
        $mailer = new Mailer(new NullTransport());
        $result = $this->controller->smtpAction($request, $mailer);
        
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
            'encryption' => 'tls'
        ]);

        $mailer = new Mailer(new NullTransport());
        $result = $this->controller->smtpAction($request, $mailer);
        
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
        // Empty options - no 'option' parameter set

        $mailer = new Mailer(new NullTransport());
        $result = $this->controller->smtpAction($request, $mailer);
        
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
            'port' => 25
            // Missing username, password, encryption
        ]);

        $mailer = new Mailer(new NullTransport());
        $result = $this->controller->smtpAction($request, $mailer);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        // Will fail to connect but shouldn't throw an error about missing params
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
    }

    public function testEmailActionReturnsCorrectStructure(): void
    {
        $request = new Request();
        $request->request->set('option', [
            'from_address' => 'test@example.com'
        ]);

        $mailer = new Mailer(new NullTransport());
        $mailModule = $this->createMock(Module::class);
        $mailModule->method('config')->willReturn([
            'from_address' => 'test@example.com',
            'from_name' => null
        ]);

        $result = $this->controller->emailAction($request, $mailer, $mailModule);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        // With NullTransport, sending should succeed
        $this->assertTrue($result['success']);
    }
}