<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Mail\Message;
use Pagekit\Mail\Mailer;
use Pagekit\Mail\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Address;

class MessageTest extends TestCase
{
    protected ?Message $message = null;
    protected ?Mailer $mailer = null;

    public function setUp(): void
    {
        $this->message = new Message();
        $this->mailer = new Mailer(new NullTransport());
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(Message::class, $this->message);
    }

    public function testSetAndGetMailer(): void
    {
        $result = $this->message->setMailer($this->mailer);
        $this->assertSame($this->message, $result);
        $this->assertSame($this->mailer, $this->message->getMailer());
    }

    public function testSendWithoutMailer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No mailer instance set. Call setMailer() first.');
        
        $this->message->send();
    }

    public function testSendWithMailer(): void
    {
        $this->message->setMailer($this->mailer);
        $this->message->from('from@example.com')
                     ->to('to@example.com')
                     ->subject('Test Subject')
                     ->text('Test Body');

        $result = $this->message->send();
        $this->assertEquals(1, $result);
    }

    public function testSendWithErrors(): void
    {
        // Create a mock mailer that throws an exception
        $mockMailer = $this->createMock(Mailer::class);
        $mockMailer->expects($this->once())
                   ->method('send')
                   ->will($this->throwException(new \Exception('Test exception')));

        $this->message->setMailer($mockMailer);
        
        $errors = [];
        $result = $this->message->send($errors);
        
        $this->assertEquals(0, $result);
        $this->assertCount(1, $errors);
        $this->assertEquals('Test exception', $errors[0]);
    }

    public function testQueue(): void
    {
        // Since queue delegates to send, test it behaves the same
        $this->message->setMailer($this->mailer);
        $this->message->from('from@example.com')
                     ->to('to@example.com')
                     ->subject('Test Subject')
                     ->text('Test Body');

        $result = $this->message->queue();
        $this->assertEquals(1, $result);
    }

    public function testAttachFile(): void
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test_attachment');
        file_put_contents($tempFile, 'Test file content');

        try {
            $result = $this->message->attachFile($tempFile, 'test.txt', 'text/plain');
            $this->assertSame($this->message, $result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testAttachData(): void
    {
        $result = $this->message->attachData('Test data', 'test.txt', 'text/plain');
        $this->assertSame($this->message, $result);
    }

    public function testEmbedFile(): void
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test_embed');
        file_put_contents($tempFile, 'Test file content');

        try {
            $cid = $this->message->embedFile($tempFile);
            $this->assertStringStartsWith('cid:', $cid);
            $this->assertStringContainsString('@pagekit', $cid);
        } finally {
            unlink($tempFile);
        }
    }

    public function testEmbedFileWithCustomCid(): void
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test_embed');
        file_put_contents($tempFile, 'Test file content');

        try {
            $customCid = 'custom-cid';
            $cid = $this->message->embedFile($tempFile, $customCid);
            // CID must match the header value (RFC requires local@domain format)
            // So 'custom-cid' becomes 'custom-cid@pagekit' in the header (consistent with embedData)
            $this->assertEquals('cid:custom-cid@pagekit', $cid);
        } finally {
            unlink($tempFile);
        }
    }

    public function testEmbedData(): void
    {
        $cid = $this->message->embedData('Test data content', 'test.txt', 'text/plain');
        $this->assertStringStartsWith('cid:', $cid);
        $this->assertStringContainsString('@pagekit', $cid);
    }

    public function testAddHeader(): void
    {
        $result = $this->message->addHeader('X-Custom-Header', 'Custom Value');
        $this->assertSame($this->message, $result);
        
        // Check that header was added
        $headers = $this->message->getHeaders();
        $this->assertTrue($headers->has('X-Custom-Header'));
        $this->assertEquals('Custom Value', $headers->get('X-Custom-Header')->getBody());
    }

    public function testGetPartsIncludesEmbeded(): void
    {
        $this->message->attachData('attachment content', 'attachment.txt');
        $this->message->embedData('embedded content', 'embedded.txt');
        
        $parts = $this->message->getParts();
        
        // Should contain both attachment and embedded parts (no duplicates)
        // attachData adds 1 part, embedData adds 1 part = 2 total
        $this->assertCount(2, $parts);
    }

    public function testEmailMethodsInheritance(): void
    {
        // Test that basic Email methods work
        $result = $this->message->from('from@example.com')
                               ->to('to@example.com')
                               ->subject('Test Subject')
                               ->text('Test Body');
                               
        $this->assertSame($this->message, $result);
        
        // Verify the values were set
        $from = $this->message->getFrom();
        $to = $this->message->getTo();
        
        $this->assertCount(1, $from);
        $this->assertEquals('from@example.com', $from[0]->getAddress());
        
        $this->assertCount(1, $to);
        $this->assertEquals('to@example.com', $to[0]->getAddress());
        
        $this->assertEquals('Test Subject', $this->message->getSubject());
        $this->assertEquals('Test Body', $this->message->getTextBody());
    }

    public function testCloneWithTempFiles(): void
    {
        // Create message with in-memory attachment
        $this->message->attachData('Test attachment data', 'test.txt', 'text/plain');
        
        // Verify original has attachment
        $originalParts = $this->message->getParts();
        $this->assertGreaterThan(0, count($originalParts));
        
        // Clone the message
        $clonedMessage = clone $this->message;
        
        // Both should have their own temp files
        $this->assertNotSame($this->message, $clonedMessage);
        
        // Verify cloned message has its own copy of attachments
        $clonedParts = $clonedMessage->getParts();
        $this->assertCount(count($originalParts), $clonedParts);
        
        // CRITICAL: Verify that cloned message's DataPart objects reference NEW temp files
        // not the original ones. If original is destroyed, clone should still work.
        // Use reflection to access protected tempFiles property
        $reflection = new \ReflectionClass($this->message);
        $tempFilesProperty = $reflection->getProperty('tempFiles');
        $tempFilesProperty->setAccessible(true);
        
        $originalTempFiles = $tempFilesProperty->getValue($this->message);
        $clonedTempFiles = $tempFilesProperty->getValue($clonedMessage);
        
        if (!empty($originalTempFiles) && !empty($clonedTempFiles)) {
            // Cloned files should be different from original
            $this->assertNotEquals($originalTempFiles, $clonedTempFiles);
            
            // Simulate original being destroyed (delete its temp files)
            foreach ($originalTempFiles as $originalFile) {
                if (file_exists($originalFile)) {
                    @unlink($originalFile);
                }
            }
            
            // Cloned message should still be able to send (uses its own temp files)
            // This verifies that DataPart objects were updated to reference new paths
            $clonedMessage->setMailer($this->mailer);
            $clonedMessage->from('from@example.com')
                         ->to('to@example.com')
                         ->subject('Test')
                         ->text('Test body');
            $result = $clonedMessage->send();
            $this->assertEquals(1, $result, 'Cloned message should work even after original temp files are deleted');
        }
    }
}