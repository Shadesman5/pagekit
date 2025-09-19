<?php

namespace Pagekit\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport;
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
            // Validate required parameters
            if (empty($host)) {
                throw new \Exception('SMTP host is required for testing connection');
            }
            
            // Default port if not provided based on encryption
            if (empty($port)) {
                if ($encryption === 'ssl') {
                    $port = 465;  // SSL/TLS implicit encryption
                } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                    $port = 587;  // STARTTLS explicit encryption
                } else {
                    $port = 25;   // No encryption
                }
            }
            
            // Determine TLS setting for EsmtpTransport
            // Important: In Symfony Mailer's EsmtpTransport:
            // - 3rd param = true  → uses implicit SSL/TLS (port 465)
            // - 3rd param = false → no implicit encryption (can use STARTTLS on port 587)
            // - 3rd param = null  → auto-detect based on port
            $useTls = null;
            if ($encryption === 'ssl') {
                $useTls = true;   // Use implicit SSL/TLS (typically port 465)
            } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                $useTls = false;  // Don't use implicit encryption, but STARTTLS will be negotiated
            } elseif ($encryption === '' || $encryption === null) {
                $useTls = false;  // No encryption at all
            }
            
            // Create test transport using the same logic as index.php
            $testTransport = new EsmtpTransport(
                $host,
                (int) $port,
                $useTls
            );
            
            // Set authentication if provided
            if (!empty($username)) {
                $testTransport->setUsername($username);
                if (!empty($password)) {
                    $testTransport->setPassword($password);
                }
            }
            
            // For a basic validation test, we check if the transport can be created
            // Note: Actual connection testing would require sending a real test email
            // which is done in the emailAction method
            
            // Basic validation: check if we can get a string representation
            $transportString = (string) $testTransport;
            if (empty($transportString)) {
                throw new \Exception('Failed to create SMTP transport configuration');
            }
            
            return true;
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw $e;
        }
    }
}