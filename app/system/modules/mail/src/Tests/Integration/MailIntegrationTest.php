<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests\Integration;

use Pagekit\Mail\Mailer;
use Pagekit\Mail\Message;
use Pagekit\Mail\Plugin\ImpersonatePlugin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

#[Group('integration')]
class MailIntegrationTest extends TestCase
{
    public function testCompleteMailWorkflow(): void
    {
        // Test the complete workflow from creating a mailer to sending an email
        $transport = new NullTransport();
        $mailer = new Mailer($transport);

        // Add impersonate plugin
        $plugin = new ImpersonatePlugin('system@example.com', 'System Mailer');
        $mailer->registerPlugin($plugin);

        // Create message
        $message = new Message();
        $message->setMailer($mailer);

        // Configure message
        $message->to('user@example.com')
                ->subject('Welcome to the system')
                ->html('<h1>Welcome!</h1><p>Thank you for registering.</p>')
                ->text('Welcome! Thank you for registering.');

        // Send message
        $result = $message->send();
        $this->assertEquals(1, $result);

        // Verify plugin applied default from address
        $from = $message->getFrom();
        $this->assertCount(1, $from);
        $this->assertEquals('system@example.com', $from[0]->getAddress());
        $this->assertEquals('System Mailer', $from[0]->getName());
    }

    public function testEmailWithAttachment(): void
    {
        $transport = new NullTransport();
        $mailer = new Mailer($transport);

        $message = new Message();
        $message->setMailer($mailer);

        // Create temporary file for attachment
        $tempFile = tempnam(sys_get_temp_dir(), 'mail_test');
        file_put_contents($tempFile, 'This is test attachment content');

        try {
            $message->from('sender@example.com')
                    ->to('recipient@example.com')
                    ->subject('Email with attachment')
                    ->text('Please find attachment')
                    ->attachFile($tempFile, 'document.txt', 'text/plain');

            $result = $message->send();
            $this->assertEquals(1, $result);

            // Verify attachment was added to parts
            $parts = $message->getParts();
            $this->assertGreaterThan(0, count($parts));
        } finally {
            unlink($tempFile);
        }
    }

    public function testEmailWithEmbeddedContent(): void
    {
        $transport = new NullTransport();
        $mailer = new Mailer($transport);

        $message = new Message();
        $message->setMailer($mailer);

        // Create temporary image file for embedding
        $tempFile = tempnam(sys_get_temp_dir(), 'mail_test_img');
        file_put_contents($tempFile, 'fake-image-content');

        try {
            $cid = $message->embedFile($tempFile, 'logo');

            $htmlBody = '<h1>Welcome!</h1><img src="' . $cid . '" alt="Logo">';

            $message->from('sender@example.com')
                    ->to('recipient@example.com')
                    ->subject('Email with embedded image')
                    ->html($htmlBody);

            $result = $message->send();
            $this->assertEquals(1, $result);

            // Verify embedded content - CID must match header value (RFC requires local@domain)
            $this->assertEquals('cid:logo@pagekit', $cid);
        } finally {
            unlink($tempFile);
        }
    }

    public function testMultiplePluginsExecution(): void
    {
        $transport = new NullTransport();
        $mailer = new Mailer($transport);

        // Add multiple plugins
        $plugin1 = new ImpersonatePlugin('first@example.com', 'First Plugin');
        $plugin2 = new ImpersonatePlugin('second@example.com', 'Second Plugin');

        $mailer->registerPlugin($plugin1);
        $mailer->registerPlugin($plugin2);

        $message = new Message();
        $message->setMailer($mailer);
        $message->to('recipient@example.com')
                ->subject('Test multiple plugins')
                ->text('Testing plugins');

        $result = $message->send();
        $this->assertEquals(1, $result);

        // First plugin should win
        $from = $message->getFrom();
        $this->assertEquals('first@example.com', $from[0]->getAddress());
        $this->assertEquals('First Plugin', $from[0]->getName());
    }

    public function testErrorHandlingInSend(): void
    {
        // Test error handling by using a mock that throws exception
        $mockTransport = $this->createMock(\Symfony\Component\Mailer\Transport\TransportInterface::class);
        $mockTransport->expects($this->once())
                     ->method('send')
                     ->will($this->throwException(new \Exception('Test transport error')));

        $mailer = new Mailer($mockTransport);

        $message = new Message();
        $message->setMailer($mailer);
        $message->from('sender@example.com')
                ->to('recipient@example.com')
                ->subject('Test error handling')
                ->text('This should fail');

        $errors = [];
        $result = $message->send($errors);

        $this->assertEquals(0, $result);
        $this->assertIsArray($errors);
        $this->assertCount(1, $errors);
        $this->assertEquals('Test transport error', $errors[0]);
    }

    public function testMessageWithCustomHeaders(): void
    {
        $transport = new NullTransport();
        $mailer = new Mailer($transport);

        $message = new Message();
        $message->setMailer($mailer);

        $message->from('sender@example.com')
                ->to('recipient@example.com')
                ->subject('Test custom headers')
                ->text('Testing headers')
                ->addHeader('X-Custom-ID', '12345')
                ->addHeader('X-Priority', 'High');

        $result = $message->send();
        $this->assertEquals(1, $result);

        // Verify headers were added
        $headers = $message->getHeaders();
        $this->assertTrue($headers->has('X-Custom-ID'));
        $this->assertTrue($headers->has('X-Priority'));
        $customIdHeader = $headers->get('X-Custom-ID');
        $this->assertNotNull($customIdHeader);
        $this->assertEquals('12345', $customIdHeader->getBody());
        $priorityHeader = $headers->get('X-Priority');
        $this->assertNotNull($priorityHeader);
        $this->assertEquals('High', $priorityHeader->getBody());
    }

    #[Group('network')]
    public function testRealSmtpConnection(): void
    {
        // Skip this test if email configuration is not available
        if (!($GLOBALS['email_smtp_host'] ?? false)) {
            $this->markTestSkipped('Email SMTP configuration not available');
        }

        $transport = new EsmtpTransport(
            (string) $GLOBALS['email_smtp_host'],
            (int)$GLOBALS['email_smtp_port'],
            $GLOBALS['email_smtp_encryption'] === 'ssl'
        );

        if (!empty($GLOBALS['email_smtp_user'])) {
            $transport->setUsername((string) $GLOBALS['email_smtp_user']);
            $transport->setPassword((string) $GLOBALS['email_smtp_password']);
        }

        $mailer = new Mailer($transport);

        // testSmtpConnection requires explicit SMTP parameters and returns bool or throws exception
        $result = $mailer->testSmtpConnection(
            $GLOBALS['email_smtp_host'],
            (int)($GLOBALS['email_smtp_port'] ?? 25),
            $GLOBALS['email_smtp_user'] ?? null,
            $GLOBALS['email_smtp_password'] ?? null,
            $GLOBALS['email_smtp_encryption'] ?? null
        );
        $this->assertTrue($result);
    }

    #[Group('network')]
    public function testActualEmailSending(): void
    {
        // Skip this test if email configuration is not available
        if (!($GLOBALS['email_address'] ?? false) || !($GLOBALS['email_to'] ?? false)) {
            $this->markTestSkipped('Email send configuration not available');
        }

        $transport = new EsmtpTransport(
            (string) $GLOBALS['email_smtp_host'],
            (int)$GLOBALS['email_smtp_port'],
            $GLOBALS['email_smtp_encryption'] === 'ssl'
        );

        if (!empty($GLOBALS['email_smtp_user'])) {
            $transport->setUsername((string) $GLOBALS['email_smtp_user']);
            $transport->setPassword((string) $GLOBALS['email_smtp_password']);
        }

        $mailer = new Mailer($transport);

        $message = new Message();
        $message->setMailer($mailer);
        $message->from($GLOBALS['email_address'])
                ->to($GLOBALS['email_to'])
                ->subject('Pagekit Mail System Test - ' . date('Y-m-d H:i:s'))
                ->html('<h1>Test Email</h1><p>This is a test email from Pagekit mail system.</p>')
                ->text('Test Email - This is a test email from Pagekit mail system.');

        $errors = [];
        $result = $message->send($errors);

        if ($result !== 1) {
            $this->fail('Email sending failed: ' . implode(', ', $errors ?? []));
        }

        $this->assertEquals(1, $result);
        $this->assertEmpty($errors);
    }
}
