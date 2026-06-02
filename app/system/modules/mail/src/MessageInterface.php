<?php

declare(strict_types=1);

namespace Pagekit\Mail;

interface MessageInterface
{
    /**
     * Gets the mailer instance.
     */
    public function getMailer(): ?MailerInterface;

    /**
     * Sets the mailer instance.
     */
    public function setMailer(MailerInterface $mailer): self;

    /**
     * Sends the message.
     *
     * @param array<int, string>|null $errors Out-parameter populated with error messages on failure.
     */
    public function send(?array &$errors = null): int;

    /**
     * Queues the message for later sending.
     *
     * @param array<int, string>|null $errors Out-parameter populated with error messages on failure.
     */
    public function queue(?array &$errors = null): int;
}
