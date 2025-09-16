<?php

namespace Pagekit\Mail\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Pagekit\Mail\Plugin\ImpersonatePlugin;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class ImpersonatePluginTest extends TestCase
{
    public function testConstructorWithoutParameters(): void
    {
        $plugin = new ImpersonatePlugin();
        $this->assertInstanceOf(ImpersonatePlugin::class, $plugin);
    }

    public function testConstructorWithParameters(): void
    {
        $plugin = new ImpersonatePlugin('test@example.com', 'Test Name');
        $this->assertInstanceOf(ImpersonatePlugin::class, $plugin);
    }

    public function testBeforeSendWithoutFromAddress(): void
    {
        $plugin = new ImpersonatePlugin('default@example.com', 'Default Name');
        
        $email = new Email();
        $email->to('recipient@example.com')
              ->subject('Test Subject');

        $plugin->beforeSend($email);
        
        $from = $email->getFrom();
        $this->assertCount(1, $from);
        $this->assertEquals('default@example.com', $from[0]->getAddress());
        $this->assertEquals('Default Name', $from[0]->getName());
    }

    public function testBeforeSendWithExistingFromAddress(): void
    {
        $plugin = new ImpersonatePlugin('default@example.com', 'Default Name');
        
        $email = new Email();
        $email->from('existing@example.com')
              ->to('recipient@example.com')
              ->subject('Test Subject');

        $plugin->beforeSend($email);
        
        $from = $email->getFrom();
        $this->assertCount(1, $from);
        // Should not override existing from address
        $this->assertEquals('existing@example.com', $from[0]->getAddress());
    }

    public function testBeforeSendWithAddressOnly(): void
    {
        $plugin = new ImpersonatePlugin('default@example.com');
        
        $email = new Email();
        $email->to('recipient@example.com')
              ->subject('Test Subject');

        $plugin->beforeSend($email);
        
        $from = $email->getFrom();
        $this->assertCount(1, $from);
        $this->assertEquals('default@example.com', $from[0]->getAddress());
        $this->assertEmpty($from[0]->getName());
    }

    public function testBeforeSendWithoutDefaultAddress(): void
    {
        $plugin = new ImpersonatePlugin();
        
        $email = new Email();
        $email->to('recipient@example.com')
              ->subject('Test Subject');

        $plugin->beforeSend($email);
        
        $from = $email->getFrom();
        $this->assertCount(0, $from); // Should remain empty
    }

    public function testAfterSend(): void
    {
        $plugin = new ImpersonatePlugin('test@example.com', 'Test Name');
        
        $email = new Email();
        $email->from('sender@example.com')
              ->to('recipient@example.com')
              ->subject('Test Subject');

        // afterSend should not modify anything
        $originalFrom = $email->getFrom();
        $plugin->afterSend($email);
        $afterFrom = $email->getFrom();
        
        $this->assertEquals($originalFrom, $afterFrom);
    }

    public function testBeforeSendWithNamedAddress(): void
    {
        $plugin = new ImpersonatePlugin('default@example.com', 'Default Name');
        
        $email = new Email();
        $email->from(new Address('existing@example.com', 'Existing Name'))
              ->to('recipient@example.com')
              ->subject('Test Subject');

        $plugin->beforeSend($email);
        
        $from = $email->getFrom();
        $this->assertCount(1, $from);
        // Should not override existing from address
        $this->assertEquals('existing@example.com', $from[0]->getAddress());
        $this->assertEquals('Existing Name', $from[0]->getName());
    }

    public function testBeforeSendWithNameOnlyInPlugin(): void
    {
        $plugin = new ImpersonatePlugin('default@example.com', 'Default Name');
        
        $email = new Email();
        $email->to('recipient@example.com')
              ->subject('Test Subject');

        $plugin->beforeSend($email);
        
        $from = $email->getFrom();
        $this->assertCount(1, $from);
        $this->assertEquals('default@example.com', $from[0]->getAddress());
        $this->assertEquals('Default Name', $from[0]->getName());
    }
}