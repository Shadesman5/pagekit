<?php

declare(strict_types=1);

namespace Pagekit\Mail;

use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class Message extends Email implements MessageInterface
{
    protected ?MailerInterface $mailer = null;
    
    /**
     * Temporary files created for in-memory data attachments/embeds.
     * These need to be kept alive until the email is sent.
     * 
     * @var array<string> Array of temporary file paths
     */
    protected array $tempFiles = [];

    /**
     * {@inheritdoc}
     */
    public function getMailer(): ?MailerInterface
    {
        return $this->mailer;
    }

    /**
     * {@inheritdoc}
     */
    public function setMailer(MailerInterface $mailer): self
    {
        $this->mailer = $mailer;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function send(&$errors = null): int
    {
        if (!$this->mailer) {
            throw new \RuntimeException('No mailer instance set. Call setMailer() first.');
        }
       
        try {
            $this->mailer->send($this);
            return 1;
        } catch (\Exception $e) {
            if ($errors !== null) {
                $errors[] = $e->getMessage();
            }
            return 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function queue(&$errors = null): int
    {
        // For now, just delegate to send since we don't have a queue implementation
        // This can be extended later to add actual queuing functionality
        return $this->send($errors);
    }

    /**
     * Attaches a file to the message.
     *
     * @param  string $file
     * @param  string $name
     * @param  string $mime
     */
    public function attachFile(string $file, ?string $name = null, ?string $mime = null): self
    {
        $this->attach($file, $name, $mime);

        return $this;
    }

    /**
     * Attaches in-memory data as an attachment.
     *
     * @param  string $data
     * @param  string $name
     * @param  string $mime
     */
    public function attachData(string $data, string $name, ?string $mime = null): self
    {
        // Create a persistent temporary file (not tmpfile() which auto-deletes)
        // Symfony's attach() stores the path lazily and reads it later when sending
        $tempFile = tempnam(sys_get_temp_dir(), 'pagekit_mail_');
        
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file for attachment. Check disk space and permissions.');
        }
        
        $bytesWritten = file_put_contents($tempFile, $data);
        if ($bytesWritten === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to write data to temporary file for attachment. Check disk space and permissions.');
        }
        
        // Store reference to keep file alive until email is sent
        $this->tempFiles[] = $tempFile;
        
        $this->attach($tempFile, $name, $mime);
        return $this;
    }

    /**
     * Embeds a file in the message and get the CID.
     *
     * @param  string $file
     * @param  string $cid
     */
    public function embedFile(string $file, ?string $cid = null): string
    {
        // Generate or use provided CID
        if ($cid !== null) {
            // For RFC compliance, Content-ID must be in format: local@domain
            // If custom CID doesn't have @, we'll add @pagekit (consistent with embedData)
            $contentId = strpos($cid, '@') === false ? $cid.'@pagekit' : $cid;
        } else {
            $contentId = md5_file($file).'@pagekit';
        }
        
        // embed() signature: embed(string|resource $body, ?string $name = null, ?string $contentType = null): string
        // Returns the CID (Content-ID)
        $this->embed($file);
        
        // Override the CID with our custom one
        // Get the last attachment (which is the embedded part)
        $attachments = $this->getAttachments();
        if (count($attachments) > 0) {
            $lastPart = $attachments[count($attachments) - 1];
            if ($lastPart instanceof DataPart) {
                // Content-ID header expects the value without angle brackets
                // The IdentificationHeader will add them automatically
                $lastPart->getHeaders()->setHeaderBody('Id', 'Content-ID', $contentId);
            }
        }
        
        // Note: No need to store in $this->embeded[] because $this->embed() already
        // adds it to getAttachments(), and getParts() uses getAttachments()
        
        // Return CID that matches the header value
        // This ensures HTML references like <img src="cid:logo@pagekit.local"> match the header
        return 'cid:'.$contentId;
    }

    /**
     * Embeds in-memory data in the message and get the CID.
     *
     * @param  string      $data
     * @param  string      $name
     * @param  string|null $contentType
     * @return string
     */
    public function embedData(string $data, string $name, ?string $contentType = null): string
    {
        // Create a persistent temporary file (not tmpfile() which auto-deletes)
        // Symfony's embed() stores the path lazily and reads it later when sending
        $tempFile = tempnam(sys_get_temp_dir(), 'pagekit_mail_');
        
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file for embedded content. Check disk space and permissions.');
        }
        
        $bytesWritten = file_put_contents($tempFile, $data);
        if ($bytesWritten === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to write data to temporary file for embedded content. Check disk space and permissions.');
        }
        
        // Store reference to keep file alive until email is sent
        $this->tempFiles[] = $tempFile;
        
        $contentId = md5($data).'@pagekit';
        // embed() signature: embed(string|resource $body, ?string $name = null, ?string $contentType = null): string
        // Returns the CID (Content-ID)
        $this->embed($tempFile, $name, $contentType ?? 'application/octet-stream');
        
        // Override the CID with our custom one
        // Get the last attachment (which is the embedded part)
        $attachments = $this->getAttachments();
        if (count($attachments) > 0) {
            $lastPart = $attachments[count($attachments) - 1];
            if ($lastPart instanceof DataPart) {
                // Content-ID header expects the value without angle brackets
                // The IdentificationHeader will add them automatically
                $lastPart->getHeaders()->setHeaderBody('Id', 'Content-ID', $contentId);
            }
        }
        
        // Note: No need to store in $this->embeded[] because $this->embed() already
        // adds it to getAttachments(), and getParts() uses getAttachments()
        
        return 'cid:'.$contentId;
    }

    /**
     * Adds a header to the message
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function addHeader(string $name, string $value): self
    {
        $this->getHeaders()->add(new UnstructuredHeader($name, $value));

        return $this;
    }

    public function getParts(): array
    {
        // getAttachments() already includes all attachments and embedded parts
        // (embedded parts are attachments with Content-ID headers)
        // The $this->embeded array is no longer needed since we removed duplicate storage
        return $this->getAttachments();
    }

    /**
     * Cleanup temporary files when message is destroyed.
     * This ensures temp files created for in-memory data are deleted after email is sent.
     */
    public function __destruct()
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Clone handler to duplicate temp files for cloned messages.
     * When a Message is cloned, we need to copy the temp files so both
     * objects have their own copies and can clean up independently.
     */
    public function __clone()
    {
        // Clear tempFiles immediately to prevent cloned object from claiming
        // ownership of original's files if an exception occurs during cloning
        $originalTempFiles = $this->tempFiles;
        $this->tempFiles = [];
        
        $newTempFiles = [];
        foreach ($originalTempFiles as $tempFile) {
            // Check if original file exists before creating new temp file
            // This prevents orphaned temp files if original was deleted externally
            if (!file_exists($tempFile)) {
                // Original file was deleted externally, skip it
                continue;
            }
            
            // Create a copy of the temp file for the cloned object
            $newTempFile = tempnam(sys_get_temp_dir(), 'pagekit_mail_');
            if ($newTempFile === false) {
                // Clean up any files we've created so far
                foreach ($newTempFiles as $createdFile) {
                    @unlink($createdFile);
                }
                throw new \RuntimeException('Failed to create temporary file for cloned message. Check disk space and permissions.');
            }
            
            if (copy($tempFile, $newTempFile)) {
                $newTempFiles[] = $newTempFile;
            } else {
                // Clean up the temp file we created
                @unlink($newTempFile);
                // Clean up any files we've created so far
                foreach ($newTempFiles as $createdFile) {
                    @unlink($createdFile);
                }
                throw new \RuntimeException('Failed to copy temporary file for cloned message. Check disk space and permissions.');
            }
        }
        $this->tempFiles = $newTempFiles;
    }
}
