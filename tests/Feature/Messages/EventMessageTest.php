<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Feature\Messages;

use JuniorFontenele\LaravelRabbitMQ\Contracts\EventMessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Messages\EventMessage;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;
use PhpAmqpLib\Message\AMQPMessage;

class EventMessageTest extends TestCase
{
    private array $validMessageData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validMessageData = [
            'timestamp' => now()->toIso8601ZuluString(),
            'nonce' => bin2hex(random_bytes(16)),
            'message_id' => '123e4567-e89b-12d3-a456-426614174000',
            'correlation_id' => '123e4567-e89b-12d3-a456-426614174001',
            'app' => 'test-app',
            'hostname' => 'test-host',
            'event' => 'test.event',
            'payload' => ['test' => 'data'],
        ];
    }

    public function testMakeCreatesValidMessage(): void
    {
        $event = 'user.created';
        $payload = ['id' => 1, 'name' => 'Test User'];
        $correlationId = '123e4567-e89b-12d3-a456-426614174001';

        $message = EventMessage::make($event, $payload, $correlationId);

        $this->assertInstanceOf(EventMessageInterface::class, $message);
        $this->assertEquals($event, $message->getEvent());
        $this->assertEquals($payload, $message->getPayload());
        $this->assertEquals($correlationId, $message->getCorrelationId());
    }

    public function testTryFromWithValidData(): void
    {
        $amqpMessage = new AMQPMessage(
            json_encode($this->validMessageData),
            ['routing_key' => 'test.routing.key']
        );

        $message = EventMessage::tryFrom($amqpMessage);

        $this->assertInstanceOf(EventMessageInterface::class, $message);
        $this->assertEquals($this->validMessageData['event'], $message->getEvent());
        $this->assertEquals($this->validMessageData['payload'], $message->getPayload());
        $this->assertEquals($this->validMessageData['message_id'], $message->getMessageId());
        $this->assertEquals($this->validMessageData['correlation_id'], $message->getCorrelationId());
    }

    public function testTryFromWithInvalidData(): void
    {
        $invalidData = $this->validMessageData;
        unset($invalidData['event']);

        $amqpMessage = new AMQPMessage(json_encode($invalidData));

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Event not found in message data.');

        EventMessage::tryFrom($amqpMessage);
    }

    public function testTryFromWithMissingRequiredField(): void
    {
        $invalidData = $this->validMessageData;
        unset($invalidData['timestamp']);

        $amqpMessage = new AMQPMessage(json_encode($invalidData));

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Key "timestamp" not found in message data.');

        EventMessage::tryFrom($amqpMessage);
    }

    public function testEventIsAccessible(): void
    {
        $event = 'user.updated';
        $message = EventMessage::make($event, ['id' => 1]);

        $this->assertEquals($event, $message->getEvent());
    }

    public function testMessageIncludesEventInDataPayload(): void
    {
        $event = 'user.deleted';
        $message = EventMessage::make($event, ['id' => 1]);

        $data = $message->getData();
        $this->assertArrayHasKey('event', $data);
        $this->assertEquals($event, $data['event']);
    }

    public function testMessageOptionsAreSet(): void
    {
        $event = 'user.created';
        $payload = ['id' => 1, 'name' => 'Test User'];
        $correlationId = '123e4567-e89b-12d3-a456-426614174001';

        $message = EventMessage::make($event, $payload, $correlationId);

        $options = $message->getOptions();
        $this->assertArrayHasKey('message_id', $options);
        $this->assertArrayHasKey('correlation_id', $options);
        $this->assertEquals($correlationId, $options['correlation_id']);
    }
}
