<?php

namespace Pagekit\Mail;

use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File; 
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
        $filePart = new File($file, $name, $mime);

        $this->addPart($filePart);

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
        $dataPart = new DataPart($data, $name, $mime);
        $this->addPart($dataPart);
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
        $filePart = new File($file);
        $contentId = $cid ?? md5_file($file).'@pagekit';
        $filePart->getPreparedHeaders()->setHeaderBody('Content-ID', '<'.$contentId.'>');
        
        $this->embeded[] = $filePart;
        $this->addPart($filePart);
        return  'cid:'.$contentId;
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
        $dataPart = new DataPart($data, $name, $contentType);
        $contentId = md5($data).'@pagekit';
        $dataPart->getPreparedHeaders()->setHeaderBody('Content-ID', '<'.$contentId.'>');
        $this->embeded[] = $dataPart;
        $this->addPart($dataPart);
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
        return array_merge(parent::getParts(),$this->embeded);
    }

    /**
     * @deprecated
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return call_user_func_array([$this, $name], $arguments);
        }
        return null;
    }
}
