<?php

namespace Pagekit\Mail\Plugin;

use Pagekit\Mail\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class ImpersonatePlugin implements MailerInterface
{
    /**
     * @var string
     */
    protected $address;

    /**
     * @var string
     */
    protected $name;

    /**
     * Constructor.
     *
     * @param string $address
     * @param string $name
     */
    public function __construct($address = null, $name = null)
    {
        $this->address = $address;
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function beforeSend(Email $message)
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
    public function afterSend(Email $message)
    {
        // No action needed after sending
    }
}