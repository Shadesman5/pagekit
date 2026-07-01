<?php

declare(strict_types=1);

namespace Pagekit\Mail;

use Symfony\Component\Mime\Email;

interface MailPluginInterface
{
    /**
     * Called before the message is sent.
     */
    public function beforeSend(Email $email): void;

    /**
     * Called after the message is sent.
     */
    public function afterSend(Email $email): void;
}
