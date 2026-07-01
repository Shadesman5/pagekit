<?php

declare(strict_types=1);

namespace Pagekit\Mail\Tests;

use Pagekit\Mail\Mailer;
use Pagekit\Mail\Message;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\NullTransport;

class MessageTest extends TestCase
{
    private Message $message;
    private Mailer $mailer;

    public function setUp(): void
    {
        $this->message = new Message();
        $this->mailer = new Mailer(new NullTransport());
    }

    public function testConstructor(): void
    {
        $this->expectNotToPerformAssertions();
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
        $this->assertIsArray($errors);
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
        $customHeader = $headers->get('X-Custom-Header');
        $this->assertNotNull($customHeader);
        $this->assertEquals('Custom Value', $customHeader->getBody());
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

        // Use reflection to access protected tempFiles property
        $reflection = new \ReflectionClass($this->message);
        $tempFilesProperty = $reflection->getProperty('tempFiles');
        $tempFilesProperty->setAccessible(true);

        $originalTempFiles = $tempFilesProperty->getValue($this->message);
        $this->assertNotEmpty($originalTempFiles, 'Original should have temp files');

        // Clone the message
        $clonedMessage = clone $this->message;

        // Both should have their own temp files
        $this->assertNotSame($this->message, $clonedMessage);

        // Verify cloned message has its own copy of attachments
        $clonedParts = $clonedMessage->getParts();
        $this->assertCount(count($originalParts), $clonedParts);

        $clonedTempFiles = $tempFilesProperty->getValue($clonedMessage);

        // Cloned files should be different from original
        $this->assertNotEquals($originalTempFiles, $clonedTempFiles, 'Clone should have different temp files');

        // CRITICAL: Verify that original's DataPart objects still reference ORIGINAL temp files
        // If clone is destroyed first, original should still work
        foreach ($clonedTempFiles as $clonedFile) {
            if (file_exists($clonedFile)) {
                @unlink($clonedFile);
            }
        }

        // Original message should still be able to send (uses its own temp files)
        // This verifies that DataPart objects were NOT modified in original
        $this->message->setMailer($this->mailer);
        $this->message->from('from@example.com')
                     ->to('to@example.com')
                     ->subject('Test Original')
                     ->text('Test body');
        $result = $this->message->send();
        $this->assertEquals(1, $result, 'Original message should work even after clone temp files are deleted');

        // Also verify clone works after original is destroyed
        $clonedMessage2 = clone $this->message;
        $clonedTempFiles2 = $tempFilesProperty->getValue($clonedMessage2);

        // Delete original's temp files
        foreach ($originalTempFiles as $originalFile) {
            if (file_exists($originalFile)) {
                @unlink($originalFile);
            }
        }

        // Cloned message should still be able to send
        $clonedMessage2->setMailer($this->mailer);
        $clonedMessage2->from('from@example.com')
                      ->to('to@example.com')
                      ->subject('Test Clone')
                      ->text('Test body');
        $result2 = $clonedMessage2->send();
        $this->assertEquals(1, $result2, 'Cloned message should work even after original temp files are deleted');
    }
}
