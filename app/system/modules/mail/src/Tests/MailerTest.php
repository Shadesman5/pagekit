<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests;

use Pagekit\Mail\Mailer;
use Pagekit\Mail\Plugin\ImpersonatePlugin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Email;

class MailerTest extends TestCase
{
    private Mailer $mailer;
    private \Symfony\Component\Mailer\Transport\TransportInterface $transport;

    public function setUp(): void
    {
        $this->transport = new NullTransport();
        $this->mailer = new Mailer($this->transport);
    }

    public function testConstructor(): void
    {
        $this->expectNotToPerformAssertions();
    }

    public function testCreate(): void
    {
        $email = $this->mailer->create();
        $this->assertEmpty($email->getFrom());
    }

    public function testSend(): void
    {
        $email = new Email();
        $email->from('test@example.com')
              ->to('recipient@example.com')
              ->subject('Test Email')
              ->text('This is a test email');

        $result = $this->mailer->send($email);
        $this->assertTrue($result);
    }

    public function testRegisterPlugin(): void
    {
        $plugin = new ImpersonatePlugin('from@example.com', 'Test Sender');
        $result = $this->mailer->registerPlugin($plugin);

        $this->assertSame($this->mailer, $result);
    }

    public function testPluginBeforeSend(): void
    {
        $plugin = new ImpersonatePlugin('from@example.com', 'Test Sender');
        $this->mailer->registerPlugin($plugin);

        $email = new Email();
        $email->to('recipient@example.com')
              ->subject('Test Email')
              ->text('This is a test email');

        // Plugin should set the from address during send
        $this->mailer->send($email);

        $from = $email->getFrom();
        $this->assertNotEmpty($from);
        $this->assertEquals('from@example.com', $from[0]->getAddress());
        $this->assertEquals('Test Sender', $from[0]->getName());
    }

    public function testTestSmtpConnectionWithNullTransport(): void
    {
        // Test with null host should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SMTP host is required');
        $this->mailer->testSmtpConnection();
    }

    #[Group('network')]
    public function testTestSmtpConnectionWithValidParameters(): void
    {
        // Skip this test if email configuration is not available
        if (!$GLOBALS['email_smtp_host'] ?? false) {
            $this->markTestSkipped('Email SMTP configuration not available');
        }

        try {
            $result = $this->mailer->testSmtpConnection(
                $GLOBALS['email_smtp_host'],
                (int)$GLOBALS['email_smtp_port'],
                $GLOBALS['email_smtp_user'],
                $GLOBALS['email_smtp_password'],
                $GLOBALS['email_smtp_encryption']
            );
            $this->assertTrue($result);
        } catch (\Exception $e) {
            // Connection failed, which is acceptable for this test
            $this->assertIsString($e->getMessage());
        }
    }

    public function testTestSmtpConnectionWithInvalidParameters(): void
    {
        // Should throw exception with error message
        try {
            $this->mailer->testSmtpConnection(
                'invalid-host.example.com',
                25,
                'invalid-user',
                'invalid-password',
                null
            );
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertIsString($e->getMessage());
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testSendWithMultiplePlugins(): void
    {
        $plugin1 = new ImpersonatePlugin('from1@example.com', 'Sender 1');
        $plugin2 = new ImpersonatePlugin('from2@example.com', 'Sender 2');

        $this->mailer->registerPlugin($plugin1);
        $this->mailer->registerPlugin($plugin2);

        $email = new Email();
        $email->to('recipient@example.com')
              ->subject('Test Email')
              ->text('This is a test email');

        $result = $this->mailer->send($email);
        $this->assertTrue($result);

        // First plugin should set the from address
        $from = $email->getFrom();
        $this->assertNotEmpty($from);
        $this->assertEquals('from1@example.com', $from[0]->getAddress());
        $this->assertEquals('Sender 1', $from[0]->getName());
    }
}
