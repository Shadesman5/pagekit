<?php

namespace Pagekit\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
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
     * Tests the SMTP connection with given parameters.
     *
     * @param string|null $host
     * @param int|null $port
     * @param string|null $username
     * @param string|null $password
     * @param string|null $encryption
     * @return bool|string True if connection successful, error message otherwise
     */
    public function testSmtpConnection($host = null, $port = null, $username = null, $password = null, $encryption = null)
    {
        try {
            // Use provided parameters or fall back to current transport
            if ($host !== null) {
                // Create a temporary transport with the provided parameters
                $testTransport = new EsmtpTransport(
                    $host,
                    $port ?: 25,
                    $encryption === 'ssl'
                );
                
                if ($username) {
                    $testTransport->setUsername($username);
                    $testTransport->setPassword($password);
                }
                
                // Test the temporary transport
                $testTransport->start();
                $testTransport->stop();
            } else {
                // Test current transport
                if ($this->transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                    $this->transport->start();
                    $this->transport->stop();
                }
            }
            
            return true;
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}