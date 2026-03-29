<?php

declare(strict_types=1);

namespace Pagekit\Mail\Controller;

use function Pagekit\__;

use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\Util\Arr;

#[Access('system: access settings', admin: true)]
class MailController
{
    public function __construct(
        private readonly mixed $request,
        private readonly mixed $mailer,
        private readonly mixed $module,
    ) {
    }

    #[Route('/smtp', methods: ['POST'])]
    public function smtpAction(): array
    {
        $option = $this->request->request->all()['option'] ?? [];
        if (empty($option) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            if (is_array($json)) {
                $option = $json['option'] ?? [];
            }
        }

        try {
            if (empty($option['host'])) {
                return ['success' => false, 'message' => __('SMTP host is required for connection testing.')];
            }

            $this->mailer->testSmtpConnection(
                $option['host'] ?? null,
                isset($option['port']) ? (int) $option['port'] : null,
                $option['username'] ?? null,
                $option['password'] ?? null,
                $option['encryption'] ?? null
            );

            return ['success' => true, 'message' => __('Connection established!')];

        } catch (\Exception $e) {

            return ['success' => false, 'message' => sprintf(__('Connection not established! (%s)'), $e->getMessage())];
        }
    }

    #[Route('/email', methods: ['POST'])]
    public function emailAction(): array
    {
        $mailModule = $this->module->get('system/mail');

        $option = $this->request->request->all()['option'] ?? [];
        if (empty($option) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            if (is_array($json)) {
                $option = $json['option'] ?? [];
            }
        }

        try {
            $config = Arr::merge($mailModule->config(), $option);

            if (empty($config['from_address'])) {
                return ['success' => false, 'message' => __('From email address is required. Please configure it in the mail settings.')];
            }

            $email = $this->mailer->create()
                ->subject(__('Test email!'))
                ->text(__('Testemail'));

            if (!empty($config['from_name'])) {
                $email->from(new \Symfony\Component\Mime\Address($config['from_address'], $config['from_name']));
            } else {
                $email->from($config['from_address']);
            }

            $email->to($config['from_address']);

            $this->mailer->send($email);

            return ['success' => true, 'message' => __('Mail successfully sent!')];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => sprintf(__('Mail delivery failed! (%s)'), $e->getMessage())];
        }
    }
}
