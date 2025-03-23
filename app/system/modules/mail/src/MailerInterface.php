<?php

namespace Pagekit\Mail;

use Symfony\Component\Mime\Email;

interface MailerInterface
{
    /**
     * Called before the message is sent.
     *
     * @param Email $message
     */
    public function beforeSend(Email $message);

    /**
     * Called after the message is sent.
     *
     * @param Email $message
     */
    public function afterSend(Email $message);
}