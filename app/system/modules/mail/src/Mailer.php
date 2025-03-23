<?php

namespace Pagekit\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class Mailer
{
    /**
     * @var TransportInterface
     */
    protected $transport;

    /**
     * @var array
     */
    protected $plugins = [];

    /**
     * Constructor.
     *
     * @param TransportInterface $transport
     */
    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Creates a new message instance.
     *
     * @return Email
     */
    public function create()
    {
        return new Email();
    }

    /**
     * Sends an email message.
     *
     * @param  Email $message
     * @return bool
     */
    public function send(Email $message)
    {
        foreach ($this->plugins as $plugin) {
            $plugin->beforeSend($message);
        }

        $mailer = new SymfonyMailer($this->transport);
        $mailer->send($message);

        foreach ($this->plugins as $plugin) {
            $plugin->afterSend($message);
        }

        return true;
    }

    /**
     * Registers a plugin.
     *
     * @param  MailerPluginInterface $plugin
     * @return self
     */
    public function registerPlugin(MailerInterface $plugin)
    {
        $this->plugins[] = $plugin;

        return $this;
    }
    
    /**
     * Tests the SMTP connection.
     *
     * @return bool|string True if connection successful, error message otherwise
     */
    public function testSmtpConnection()
    {
        try {
            // Create a test email
            $email = new Email();
            $email->subject('Test Connection')
                  ->text('This is a test email to verify SMTP connection.')
                  ->to('test@example.com')
                  ->from('test@example.com');
            
            // Instead of calling ping(), we'll use a reflection trick to access the transport
            $reflectionClass = new \ReflectionClass($this->transport);
            $reflectionProperty = $reflectionClass->getProperty('stream');
            $reflectionProperty->setAccessible(true);
            
            // Just try to get the stream - this will attempt to connect
            // If there's no connection error, we're good
            if ($this->transport instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) {
                $this->transport->start();
                $this->transport->stop();
            }
            
            return true;
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}