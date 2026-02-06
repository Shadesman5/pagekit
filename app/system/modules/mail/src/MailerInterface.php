<?php

declare(strict_types=1);

namespace Pagekit\Mail;

use Symfony\Component\Mime\Email;

interface MailerInterface
{
    /**
     * Called before the message is sent.
     */
    public function beforeSend(Email $message): void;

    /**
     * Called after the message is sent.
     */
    public function afterSend(Email $message): void;
}