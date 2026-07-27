<?php

declare(strict_types=1);

namespace Pagekit\Mail\Plugin;

use Pagekit\Mail\MailPluginInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ImpersonatePlugin implements MailPluginInterface
{
    protected ?string $address;
    protected ?string $name;

    public function __construct(?string $address = null, ?string $name = null)
    {
        $this->address = $address;
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function beforeSend(Email $message): void
    {
        if ($this->address && !$message->getFrom()) {
            if ($this->name) {
                $message->from(new Address($this->address, $this->name));
            } else {
                $message->from($this->address);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function afterSend(Email $message): void
    {
        // No action needed after sending
    }
}
