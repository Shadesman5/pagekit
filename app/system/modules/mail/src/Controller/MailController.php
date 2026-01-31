<?php

declare(strict_types=1);

namespace Pagekit\Mail\Controller;

use Pagekit\Application as App;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\Util\Arr;
use Pagekit\Mail\Mailer;
use Pagekit\Module\Module;
use function Pagekit\__;

#[Access('system: access settings', admin: true)]
class MailController
{
    #[Route('/smtp', methods: ['POST'])]
    public function smtpAction(?\Symfony\Component\HttpFoundation\Request $request = null, ?Mailer $mailer = null): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = $request ?? App::request();
        $mailer = $mailer ?? App::mailer();
        
        $option = $request->request->all()['option'] ?? [];
        if (empty($option) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $option = $json['option'] ?? [];
        }
        
        try {
            // Validate that we have at least the required SMTP parameters
            if (empty($option['host'])) {
                return ['success' => false, 'message' => __('SMTP host is required for connection testing.')];
            }

            $mailer->testSmtpConnection(
                $option['host'] ?? null,
                $option['port'] ?? null,
                $option['username'] ?? null,
                $option['password'] ?? null,
                $option['encryption'] ?? null
            );

            return ['success' => true, 'message' => __('Connection established!')];

        } catch (\Exception $e) {

            return ['success' => false, 'message' => sprintf(__('Connection not established! (%s)'), $e->getMessage())];
        }
    }

    /**
     * Note: If the mailer is accessed prior to this controller action, this will possibly test the wrong mailer
     */
    #[Route('/email', methods: ['POST'])]
    public function emailAction(?\Symfony\Component\HttpFoundation\Request $request = null, ?Mailer $mailer = null, ?Module $mailModule = null): array
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $request = $request ?? App::request();
        $mailer = $mailer ?? App::mailer();
        $mailModule = $mailModule ?? App::module('system/mail');
        
        $option = $request->request->all()['option'] ?? [];
        if (empty($option) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $option = $json['option'] ?? [];
        }
        
        try {
            $config = Arr::merge($mailModule->config(), $option);
            
            $email = $mailer->create()
                ->subject(__('Test email!'))
                ->text(__('Testemail'));
                
            // Set from address with optional name
            if (!empty($config['from_name'])) {
                $email->from(new \Symfony\Component\Mime\Address($config['from_address'], $config['from_name']));
            } else {
                $email->from($config['from_address']);
            }
            
            // Send to the same address as the from address
            $email->to($config['from_address']);
                
            $mailer->send($email);
            
            return ['success' => true, 'message' => __('Mail successfully sent!')];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => sprintf(__('Mail delivery failed! (%s)'), $e->getMessage())];
        }
    }
}
