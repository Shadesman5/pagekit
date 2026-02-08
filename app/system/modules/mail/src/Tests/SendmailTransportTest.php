<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\SendmailTransport;

class SendmailTransportTest extends TestCase
{
    public function testSendmailPathWithMailpit(): void
    {
        // Simulate Windows Mailpit path
        $path = 'C:/laragon/bin/mailpit/1.22.3/mailpit.exe sendmail';
        
        // This should be processed to add -t flag
        $expectedPath = $path . ' -t';
        
        // Test that SendmailTransport accepts the path with -t flag
        $transport = new SendmailTransport($expectedPath);
        $this->assertInstanceOf(SendmailTransport::class, $transport);
    }
    
    public function testSendmailPathWithFlags(): void
    {
        // Test various valid sendmail paths
        $validPaths = [
            '/usr/sbin/sendmail -bs',
            '/usr/sbin/sendmail -t',
            'C:/path/to/sendmail.exe -t',
            '/usr/local/bin/sendmail -t -i'
        ];
        
        foreach ($validPaths as $path) {
            $transport = new SendmailTransport($path);
            $this->assertInstanceOf(SendmailTransport::class, $transport);
        }
    }
    
    public function setUp(): void
    {
        // Load translation stub for tests (CSP-safe, no eval)
        require_once __DIR__ . '/bootstrap.php';
    }
    
    public function testSmtpActionWithEmptyOptions(): void
    {
        $controller = new \Pagekit\Mail\Controller\MailController();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $mailer = new \Pagekit\Mail\Mailer(new \Symfony\Component\Mailer\Transport\NullTransport());
        
        $result = $controller->smtpAction($request, $mailer);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('required', $result['message']);
    }
    
    public function testSmtpActionWithOnlyHost(): void
    {
        $controller = new \Pagekit\Mail\Controller\MailController();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->request->set('option', ['host' => 'smtp.example.com']);
        $mailer = new \Pagekit\Mail\Mailer(new \Symfony\Component\Mailer\Transport\NullTransport());
        
        $result = $controller->smtpAction($request, $mailer);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        // This will fail to connect but shouldn't throw an error about missing params
        $this->assertFalse($result['success']);
    }
}