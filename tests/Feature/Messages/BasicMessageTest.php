<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Feature\Messages;

use JuniorFontenele\LaravelRabbitMQ\Contracts\MessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Messages\BasicMessage;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;
use PhpAmqpLib\Message\AMQPMessage;

class BasicMessageTest extends TestCase
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
            'payload' => ['test' => 'data'],
        ];
    }

    public function testMakeCreatesValidMessage(): void
    {
        $payload = ['foo' => 'bar'];
        $correlationId = '123e4567-e89b-12d3-a456-426614174001';

        $message = BasicMessage::make($payload, $correlationId);

        $this->assertInstanceOf(MessageInterface::class, $message);
        $this->assertEquals($payload, $message->getPayload());
        $this->assertEquals($correlationId, $message->getCorrelationId());
    }

    public function testTryFromWithValidData(): void
    {
        $amqpMessage = new AMQPMessage(
            json_encode($this->validMessageData),
            ['routing_key' => 'test.routing.key']
        );

        $message = BasicMessage::tryFrom($amqpMessage);

        $this->assertInstanceOf(MessageInterface::class, $message);
        $this->assertEquals($this->validMessageData['payload'], $message->getPayload());
        $this->assertEquals($this->validMessageData['message_id'], $message->getMessageId());
        $this->assertEquals($this->validMessageData['correlation_id'], $message->getCorrelationId());
    }

    public function testTryFromWithInvalidData(): void
    {
        $invalidData = $this->validMessageData;
        unset($invalidData['timestamp']);

        $amqpMessage = new AMQPMessage(json_encode($invalidData));

        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Key "timestamp" not found in message data.');

        BasicMessage::tryFrom($amqpMessage);
    }

    public function testMessageOptionsAreSet(): void
    {
        $payload = ['test' => 'data'];
        $correlationId = '123e4567-e89b-12d3-a456-426614174001';

        $message = BasicMessage::make($payload, $correlationId);

        $options = $message->getOptions();
        $this->assertArrayHasKey('message_id', $options);
        $this->assertArrayHasKey('correlation_id', $options);
        $this->assertEquals($correlationId, $options['correlation_id']);
    }

    public function testAckNackRejectMethods(): void
    {
        $amqpMessage = $this->createMock(AMQPMessage::class);
        $amqpMessage->expects($this->once())->method('ack')->with(false);
        $amqpMessage->expects($this->once())->method('nack')->with(false, true);
        $amqpMessage->expects($this->once())->method('reject')->with(true);

        $message = BasicMessage::make(['test' => 'data']);
        $message->setAMQPMessageInstance($amqpMessage);

        $this->assertTrue($message->ack());
        $this->assertTrue($message->nack());
        $this->assertTrue($message->reject());
    }

    public function testAckNackRejectMethodsReturnFalseWhenNoAMQPMessage(): void
    {
        $message = BasicMessage::make(['test' => 'data']);

        $this->assertFalse($message->ack());
        $this->assertFalse($message->nack());
        $this->assertFalse($message->reject());
    }
}
