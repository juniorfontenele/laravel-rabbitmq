<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Feature;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Messages\BasicMessage;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;

class MessageTest extends TestCase
{
    /**
     * Test that getSenderId() returns the correct sender ID when it's set
     */
    public function testGetSenderIdReturnsCorrectValue(): void
    {
        // Create a message with a known sender ID
        $message = BasicMessage::make();
        $senderId = 'test-sender';
        $message->senderId($senderId);

        // Verify the method returns the correct sender ID
        $this->assertEquals($senderId, $message->getSenderId());
    }

    /**
     * Test that getSenderId() returns the default sender ID from config
     */
    public function testGetSenderIdReturnsDefaultValue(): void
    {
        // Mock the config value
        config(['rabbitmq.sender_id' => 'default-sender']);

        // Create a message which should use the default sender ID
        $message = BasicMessage::make();

        // Verify the method returns the default sender ID
        $this->assertEquals('default-sender', $message->getSenderId());
    }

    /**
     * Test that getSenderId() throws an exception when sender ID is not set
     */
    public function testGetSenderIdThrowsExceptionWhenNotSet(): void
    {
        // Create a message
        $message = BasicMessage::make();

        // Manually remove the sender_id from the data array using reflection
        $reflection = new \ReflectionClass($message);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setAccessible(true);
        $data = $dataProperty->getValue($message);
        unset($data['sender_id']);
        $dataProperty->setValue($message, $data);

        // Expect an exception when getSenderId() is called
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Message sender ID is not set.');

        $message->getSenderId();
    }
}
