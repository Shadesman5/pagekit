<?php

namespace Pagekit\Mail\Controller;

use Pagekit\Application as App;
use Pagekit\Util\Arr;

/**
 * @Access("system: access settings", admin=true)
 */
class MailController
{
    /**
     * @Request({"option": "array"}, csrf=true)
     */
    public function smtpAction($option = []): array
    {
        try {

            App::mailer()->testSmtpConnection($option['host'], $option['port'], $option['username'], $option['password'], $option['encryption']);

            return ['success' => true, 'message' => __('Connection established!')];

        } catch (\Exception $e) {

            return ['success' => false, 'message' => sprintf(__('Connection not established! (%s)'), $e->getMessage())];
        }
    }

    /**
     * Note: If the mailer is accessed prior to this controller action, this will possibly test the wrong mailer
     *
     * @Request({"option": "array"}, csrf=true)
     */
    public function emailAction($option = []): array
    {
        try {
            $config = Arr::merge(App::module('system/mail')->config(), $option);
            
            $mailer = App::mailer();
            $email = $mailer->create()
                ->subject(__('Test email!'))
                ->text(__('Testemail'))
                ->from($config['from_address'])
                ->to($config['from_address']); // Send to the same address as the from address
                
            $mailer->send($email);
            
            return ['success' => true, 'message' => __('Mail successfully sent!')];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => sprintf(__('Mail delivery failed! (%s)'), $e->getMessage())];
        }
    }
}
