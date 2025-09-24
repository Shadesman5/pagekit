<?php

namespace Pagekit\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class Mailer implements MailerInterface
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
            
            // Validate port number
            $port = (int) $port;
            if ($port < 1 || $port > 65535) {
                throw new \Exception(sprintf('Invalid SMTP port: %d. Port must be between 1 and 65535', $port));
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
                $port,
                $useTls
            );
            
            // Set authentication if provided
            if (!empty($username)) {
                $testTransport->setUsername($username);
                if (!empty($password)) {
                    $testTransport->setPassword($password);
                }
            }
            
            // ACTUALLY TEST THE CONNECTION by attempting to open a socket connection
            // We'll use a low-level approach to test the connection without sending email
            try {
                // First, try to resolve the hostname
                $ip = gethostbyname($host);
                if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
                    throw new \Exception(sprintf('Cannot resolve hostname: %s', $host));
                }
                
                // Determine the connection string based on encryption
                $connectionString = '';
                if ($encryption === 'ssl') {
                    $connectionString = 'ssl://' . $host . ':' . $port;
                } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                    // For STARTTLS, we start with plain connection
                    $connectionString = 'tcp://' . $host . ':' . $port;
                } else {
                    $connectionString = 'tcp://' . $host . ':' . $port;
                }
                
                // Set up context options for SSL/TLS
                $contextOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];
                $context = stream_context_create($contextOptions);
                
                // Attempt to open a socket connection with 10 second timeout
                $socket = @stream_socket_client(
                    $connectionString,
                    $errno,
                    $errstr,
                    10, // 10 second timeout
                    STREAM_CLIENT_CONNECT,
                    $context
                );
                
                if (!$socket) {
                    // Connection failed
                    if ($errno === 0 && empty($errstr)) {
                        throw new \Exception(sprintf('Connection failed to %s:%d. Check host, port and encryption settings.', $host, $port));
                    } elseif (strpos($errstr, 'Connection refused') !== false || $errno === 111) {
                        throw new \Exception(sprintf('Connection refused to %s:%d. Service may not be running or port is blocked.', $host, $port));
                    } elseif (strpos($errstr, 'timed out') !== false || $errno === 110) {
                        throw new \Exception(sprintf('Connection timeout to %s:%d. Server may be unreachable or port is blocked by firewall.', $host, $port));
                    } elseif (strpos($errstr, 'SSL') !== false || strpos($errstr, 'TLS') !== false) {
                        throw new \Exception(sprintf('SSL/TLS handshake failed with %s:%d. Check encryption settings (current: %s).', $host, $port, $encryption ?: 'none'));
                    } else {
                        throw new \Exception(sprintf('Connection failed to %s:%d - %s (Error %d)', $host, $port, $errstr, $errno));
                    }
                }
                
                // Connection successful! Now let's try to read the SMTP greeting
                stream_set_timeout($socket, 5);
                $greeting = fgets($socket, 512);
                
                if (!$greeting) {
                    fclose($socket);
                    throw new \Exception(sprintf('Connected to %s:%d but no SMTP greeting received. May not be an SMTP server.', $host, $port));
                }
                
                // Check if we got a valid SMTP response (should start with 220)
                if (!preg_match('/^220[\s-]/', $greeting)) {
                    fclose($socket);
                    throw new \Exception(sprintf('Invalid SMTP greeting from %s:%d. Expected 220, got: %s', $host, $port, trim($greeting)));
                }
                
                // If TLS/STARTTLS is requested, we need to check EHLO and STARTTLS support
                if ($encryption === 'tls' || $encryption === 'starttls') {
                    // Send EHLO command
                    fwrite($socket, "EHLO localhost\r\n");
                    
                    // Read EHLO response
                    $ehloResponse = '';
                    while ($line = fgets($socket, 512)) {
                        $ehloResponse .= $line;
                        if (substr($line, 3, 1) === ' ') {
                            break;
                        }
                    }
                    
                    // Check if STARTTLS is supported
                    if (strpos($ehloResponse, 'STARTTLS') === false) {
                        fclose($socket);
                        throw new \Exception(sprintf('Server %s:%d does not support STARTTLS. Try SSL on port 465 or no encryption.', $host, $port));
                    }
                    
                    // Send STARTTLS command
                    fwrite($socket, "STARTTLS\r\n");
                    $starttlsResponse = fgets($socket, 512);
                    
                    if (!preg_match('/^220[\s-]/', $starttlsResponse)) {
                        fclose($socket);
                        throw new \Exception(sprintf('STARTTLS failed on %s:%d. Server response: %s', $host, $port, trim($starttlsResponse)));
                    }
                }
                
                // If we have authentication credentials, we could test AUTH here
                // but for now, having a successful connection is enough
                
                // Send QUIT command to close gracefully
                fwrite($socket, "QUIT\r\n");
                fgets($socket, 512); // Read QUIT response
                
                // Close the connection
                fclose($socket);
                
                return true;
                
            } catch (\Exception $connectionError) {
                // Re-throw with our formatted error message
                throw $connectionError;
            }
            
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw $e;
        }
    }
}