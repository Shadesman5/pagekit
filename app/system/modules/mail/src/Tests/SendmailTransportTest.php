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
        $this->expectNotToPerformAssertions();
        new SendmailTransport($expectedPath);
    }

    public function testSendmailPathWithFlags(): void
    {
        $this->expectNotToPerformAssertions();
        // Test various valid sendmail paths
        $validPaths = [
            '/usr/sbin/sendmail -bs',
            '/usr/sbin/sendmail -t',
            'C:/path/to/sendmail.exe -t',
            '/usr/local/bin/sendmail -t -i',
        ];

        foreach ($validPaths as $path) {
            new SendmailTransport($path);
        }
    }

    public function setUp(): void
    {
        // Load translation stub for tests (CSP-safe, no eval)
        require_once __DIR__ . '/bootstrap.php';
    }

    private function createMailController(\Symfony\Component\HttpFoundation\Request $request, ?\Pagekit\Mail\Mailer $mailer = null): \Pagekit\Mail\Controller\MailController
    {
        $mailer = $mailer ?? new \Pagekit\Mail\Mailer(new \Symfony\Component\Mailer\Transport\NullTransport());
        $module = $this->createMock(\Pagekit\Module\ModuleManager::class);

        return new \Pagekit\Mail\Controller\MailController($request, $mailer, $module);
    }

    public function testSmtpActionWithEmptyOptions(): void
    {
        $request = new \Symfony\Component\HttpFoundation\Request();
        $controller = $this->createMailController($request);

        $result = $controller->smtpAction();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('required', $result['message']);
    }

    public function testSmtpActionWithOnlyHost(): void
    {
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->request->set('option', ['host' => 'smtp.example.com']);
        $controller = $this->createMailController($request);

        $result = $controller->smtpAction();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }
}
