<?php

declare(strict_types=1);

use Pagekit\Mail\Mailer;
use Pagekit\Mail\Plugin\ImpersonatePlugin;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

return [
    'name' => 'system/mail',

    'main' => function ($app) {

        $app->set('mailer', function ($app) {

            $app->set('mailer.initialized', true);

            $mailer = new Mailer($app->get('mailer.transport'));
            $mailer->registerPlugin(new ImpersonatePlugin($this->config['from_address'], $this->config['from_name']));

            return $mailer;
        });

        $app->set('mailer.initialized', false);
        $app->set('mailer.transport', function ($app) {
            $driver = $this->config['driver'];

            if ($driver === 'smtp') {
                // Determine TLS setting based on encryption type
                // Important: In Symfony Mailer's EsmtpTransport:
                // - 3rd param = true  → uses implicit SSL/TLS (port 465)
                // - 3rd param = false → no implicit encryption (but STARTTLS can be negotiated on port 587)
                // - 3rd param = null  → auto-detect based on port
                $useTls = null;
                if ($this->config['encryption'] === 'ssl') {
                    $useTls = true;   // Use implicit SSL/TLS (typically port 465)
                } elseif ($this->config['encryption'] === 'tls' || $this->config['encryption'] === 'starttls') {
                    $useTls = false;  // Don't use implicit encryption, STARTTLS will be negotiated
                } elseif (empty($this->config['encryption'])) {
                    $useTls = false;  // No encryption at all
                }

                $transport = new EsmtpTransport(
                    $this->config['host'],
                    (int) $this->config['port'],
                    $useTls
                );

                if ($this->config['username']) {
                    $transport->setUsername($this->config['username']);
                    $transport->setPassword($this->config['password']);
                }

                return $transport;
            }

            if ($driver === 'mail') {
                $sendMailPath = ini_get('sendmail_path') ?: '/usr/sbin/sendmail -bs';

                // Fix for Windows/Mailpit: Ensure sendmail path has proper flags
                if ($sendMailPath && !preg_match('/\s+-(bs|t)(\s|$)/', $sendMailPath)) {
                    // If no valid flags are present, append -t flag
                    if (strpos($sendMailPath, 'mailpit') !== false || stripos(PHP_OS, 'WIN') === 0) {
                        // For Mailpit or Windows systems, use -t flag
                        $sendMailPath .= ' -t';
                    } else {
                        // For Unix-like systems, default to -bs
                        $sendMailPath .= ' -bs';
                    }
                }

                return new SendmailTransport($sendMailPath);
            }

            throw new \InvalidArgumentException(sprintf('Unsupported mail driver: %s', $driver));
        });

    },

    'autoload' => [

        'Pagekit\\Mail\\' => 'src',

    ],

    'routes' => [

        '/system' => [
            'name' => '@system', 'controller' => 'Pagekit\\Mail\\Controller\\MailController',
        ],

    ],
    'events' => [

        'view.system:modules/settings/views/settings' => function ($event, $view) use ($app) {
            $view->data('$mail', ['ssl' => extension_loaded('openssl')]);
            $view->data('$settings', ['options' => [$this->name => $this->config]]);
            $view->script('settings-mail', 'app/system/modules/mail/app/bundle/settings.js', 'settings');
        },

    ],
    'config' => [

        'driver' => 'mail',
        'host' => 'localhost',
        'port' => 25,
        'username' => null,
        'password' => null,
        'encryption' => null,
        'auth_mode' => null,
        'from_name' => null,
        'from_address' => null,
    ],

];
