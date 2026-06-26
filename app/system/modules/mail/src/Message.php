<?php

declare(strict_types=1);

namespace Pagekit\Mail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Part\DataPart;

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
    public function send(?array &$errors = null): int
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
    public function queue(?array &$errors = null): int
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
        $this->attachFromPath($file, $name, $mime);

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
        // We use attachFromPath() which reads the file when sending
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

        // Use attachFromPath() for file paths, not attach() which expects raw content
        $this->attachFromPath($tempFile, $name, $mime);

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

        // Use embedFromPath() for file paths, not embed() which expects raw content
        $this->embedFromPath($file);

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
        // We use embedFromPath() which reads the file when sending
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
        // Use embedFromPath() for file paths, not embed() which expects raw content
        $this->embedFromPath($tempFile, $name, $contentType ?? 'application/octet-stream');

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

    /**
     * @return array<int, DataPart>
     */
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
     *
     * Also updates DataPart objects in parent Email class to reference
     * the new temp file paths, since they are shallow-copied and still
     * point to original files.
     */
    public function __clone()
    {
        // Clear tempFiles immediately to prevent cloned object from claiming
        // ownership of original's files if an exception occurs during cloning
        $originalTempFiles = $this->tempFiles;
        $this->tempFiles = [];

        // Create mapping of original paths to new paths
        $pathMapping = [];
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
                $pathMapping[$tempFile] = $newTempFile;
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

        // Update DataPart objects in parent Email class to reference new paths
        // The parent's attachments are shallow-copied and still point to original files
        if (!empty($pathMapping)) {
            $this->updateAttachmentPaths($pathMapping);
        }
    }

    /**
     * Replaces DataPart objects in parent Email class with cloned versions referencing new temp file paths.
     * This is necessary because __clone() shallow-copies the attachments, leaving
     * them pointing to the original temp files. We must clone the DataPart objects
     * to avoid modifying the shared references that would corrupt the original message.
     *
     * @param array<string, string> $pathMapping Mapping of original paths to new paths
     */
    protected function updateAttachmentPaths(array $pathMapping): void
    {
        $attachments = $this->getAttachments();
        $attachmentsToReplace = [];

        // First pass: Identify which attachments need to be replaced and create new DataPart objects
        foreach ($attachments as $index => $attachment) {
            if ($attachment instanceof DataPart) {
                // Check if this DataPart references one of our temp files
                $originalPath = $this->getDataPartFilePath($attachment);

                if ($originalPath !== null && isset($pathMapping[$originalPath])) {
                    // Create a new DataPart with the new path instead of modifying the shared one
                    $newPath = $pathMapping[$originalPath];

                    // Get attachment metadata before creating new one
                    $headers = $attachment->getHeaders();
                    $contentId = null;
                    if ($headers->has('Content-ID')) {
                        $header = $headers->get('Content-ID');
                        if ($header !== null) {
                            $contentId = $header->getBody();
                        }
                    }

                    // Check if it's inline (embedded) or attachment
                    $isInline = $headers->has('Content-ID');

                    // Create new DataPart with new path
                    $newDataPart = DataPart::fromPath($newPath);
                    if ($isInline) {
                        $newDataPart->asInline();
                        if ($contentId !== null) {
                            $newDataPart->getHeaders()->setHeaderBody('Id', 'Content-ID', $contentId);
                        }
                    }

                    // Copy other headers from original
                    // Note: Symfony's Headers::all() yields individual HeaderInterface objects (not arrays)
                    // with the header name as the key, so we iterate directly over the headers
                    foreach ($headers->all() as $headerName => $header) {
                        $headerNameLower = strtolower($headerName);
                        if ($headerNameLower !== 'content-id' && $headerNameLower !== 'content-type') {
                            $newDataPart->getHeaders()->add($header);
                        }
                    }

                    $attachmentsToReplace[$index] = $newDataPart;
                }
            }
        }

        // Second pass: Replace attachments in parent Email class
        if (!empty($attachmentsToReplace)) {
            $this->replaceAttachments($attachmentsToReplace);
        }
    }

    /**
     * Gets the file path from a DataPart object using reflection.
     *
     * @param DataPart $dataPart
     * @return string|null The file path if found, null otherwise
     */
    protected function getDataPartFilePath(DataPart $dataPart): ?string
    {
        try {
            $reflection = new \ReflectionClass($dataPart);

            // Try body property first
            if ($reflection->hasProperty('body')) {
                $bodyProperty = $reflection->getProperty('body');
                $bodyProperty->setAccessible(true);
                $body = $bodyProperty->getValue($dataPart);

                if (is_string($body) && file_exists($body)) {
                    return $body;
                }
            }

            // Try alternative property names
            foreach (['path', 'filename', 'file'] as $propName) {
                if ($reflection->hasProperty($propName)) {
                    $prop = $reflection->getProperty($propName);
                    $prop->setAccessible(true);
                    $value = $prop->getValue($dataPart);

                    if (is_string($value) && file_exists($value)) {
                        return $value;
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // Reflection failed, can't determine path
        }

        return null;
    }

    /**
     * Replaces attachments in parent Email class using reflection.
     * Creates new DataPart objects instead of modifying shared ones to prevent
     * corrupting the original message's attachments.
     *
     * @param array<int, DataPart> $replacements Mapping of attachment index to new DataPart
     */
    protected function replaceAttachments(array $replacements): void
    {
        try {
            // Use reflection to access parent Email's internal structure
            $reflection = new \ReflectionClass($this);
            $parentReflection = $reflection->getParentClass();

            if ($parentReflection) {
                // Symfony's Email class stores attachments in a private property
                // We need to find and replace them
                $allProperties = $parentReflection->getProperties();

                foreach ($allProperties as $prop) {
                    $prop->setAccessible(true);
                    $value = $prop->getValue($this);

                    // Look for an array that contains our DataPart objects
                    if (is_array($value) || $value instanceof \Traversable) {
                        $found = false;
                        $arrayToModify = is_array($value) ? $value : iterator_to_array($value);

                        // Check if this array contains our DataPart objects
                        foreach ($arrayToModify as $item) {
                            if ($item instanceof DataPart) {
                                $found = true;

                                break;
                            }
                        }

                        if ($found) {
                            // Replace DataPart objects at specified indices
                            foreach ($replacements as $index => $newDataPart) {
                                if (isset($arrayToModify[$index]) && $arrayToModify[$index] instanceof DataPart) {
                                    $arrayToModify[$index] = $newDataPart;
                                }
                            }

                            // Update the property with modified array
                            if (is_array($value)) {
                                $prop->setValue($this, $arrayToModify);
                            } else {
                                // If it's a collection, we might need to clear and re-add
                                // For now, try setting it as array (Email should handle it)
                                $prop->setValue($this, $arrayToModify);
                            }

                            return;
                        }
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // If reflection fails, we can't replace attachments
            // Fallback: The cloned message will still reference original files
            // This means both original and clone must exist together
            // This is a limitation, but better than corrupting the original
        }
    }
}
