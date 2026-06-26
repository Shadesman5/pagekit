<?php

declare(strict_types=1);

namespace Pagekit\Mail;

use Symfony\Component\Mime\Email;

interface MailerInterface
{
    /**
     * Sends an email message.
     */
    public function send(Email $message): bool;

    /**
     * Creates a new message instance.
     */
    public function create(): Message;

    /**
     * Registers a plugin.
     */
    public function registerPlugin(MailPluginInterface $plugin): void;
}
