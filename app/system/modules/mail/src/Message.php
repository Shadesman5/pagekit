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

    protected array $embeded = [];

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
        // Create temporary file for in-memory data
        $tempFile = tmpfile();
        fwrite($tempFile, $data);
        $meta = stream_get_meta_data($tempFile);
        $this->attach($meta['uri'], $name, $mime);
        // Note: The temp file will be cleaned up by PHP when the script ends
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
            // Store original CID for return value
            $originalCid = $cid;
            // For RFC compliance, Content-ID must be in format: local@domain
            // If custom CID doesn't have @, we'll add @pagekit.local internally
            $contentId = strpos($cid, '@') === false ? $cid.'@pagekit.local' : $cid;
        } else {
            $originalCid = md5_file($file);
            $contentId = $originalCid.'@pagekit';
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
        
        // Store reference for getParts()
        $dataPart = DataPart::fromPath($file);
        $dataPart->asInline();
        $this->embeded[] = $dataPart;
        
        // Return CID in expected format
        // If custom CID was provided, return it as-is (without @)
        // Otherwise return with @pagekit for consistency
        if ($cid !== null) {
            return 'cid:'.$cid;
        }
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
        // Create temporary file for in-memory data
        $tempFile = tmpfile();
        fwrite($tempFile, $data);
        $meta = stream_get_meta_data($tempFile);
        
        $contentId = md5($data).'@pagekit';
        // embed() signature: embed(string|resource $body, ?string $name = null, ?string $contentType = null): string
        // Returns the CID (Content-ID)
        $cid = $this->embed($meta['uri'], $name, $contentType ?? 'application/octet-stream');
        
        // Override the CID with our custom one
        // Get the last attachment (which is the embedded part)
        $attachments = $this->getAttachments();
        if (count($attachments) > 0) {
            $lastPart = $attachments[count($attachments) - 1];
            if ($lastPart instanceof DataPart) {
                // Content-ID must be in valid message-id format (local@domain)
                // If custom CID doesn't have @, add a domain
                if (strpos($contentId, '@') === false) {
                    $contentId = $contentId.'@pagekit.local';
                }
                // Content-ID header expects the value without angle brackets
                // The IdentificationHeader will add them automatically
                $lastPart->getHeaders()->setHeaderBody('Id', 'Content-ID', $contentId);
            }
        }
        
        // Store reference for getParts()
        $dataPart = new DataPart($data, $name, $contentType ?? 'application/octet-stream');
        $dataPart->asInline();
        $this->embeded[] = $dataPart;
        
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
        // Email doesn't have getParts(), but we can get attachments
        $parts = [];
        foreach ($this->getAttachments() as $attachment) {
            $parts[] = $attachment;
        }
        // Also include embedded parts
        foreach ($this->embeded as $embedded) {
            $parts[] = $embedded;
        }
        return $parts;
    }
}
