<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Messages;

use Illuminate\Support\Str;
use JuniorFontenele\LaravelRabbitMQ\Contracts\MessageInterface;
use PhpAmqpLib\Message\AMQPMessage;

class BasicMessage extends AbstractMessage
{
    /**
     * BasicMessage constructor.
     *
     * @param array<string, mixed> $data The message data
     */
    final protected function __construct(array $data = [])
    {
        $this->data = $data;

        $this->options['message_id'] = $this->data['message_id'];
        $this->options['correlation_id'] = $this->data['correlation_id'];

        if ($this->signingIsEnabled()) {
            $this->signMessage();
        }
    }

    /**
     * Create a message instance from an AMQPMessage.
     *
     * @param AMQPMessage $AMQPMessage The AMQP message
     * @throws \JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException
     * @return MessageInterface
     */
    public static function tryFrom(AMQPMessage $AMQPMessage): MessageInterface
    {
        $data = json_decode($AMQPMessage->getBody(), true, 512, JSON_THROW_ON_ERROR);

        static::validateMessageData($data);

        $message = new static(data: $data);

        return $message->routingKey($AMQPMessage->getRoutingKey() ?? '')
            ->options($AMQPMessage->get_properties())
            ->setAMQPMessageInstance($AMQPMessage);
    }

    /**
     * Create a new basic message instance.
     *
     * @param array<string, mixed> $payload
     * @param string $correlationId
     * @return static
     */
    public static function make(array $payload = [], string $correlationId = ''): static
    {
        $messageId = Str::uuid()->toString();
        $nonce = bin2hex(random_bytes(16));

        $data = [
            'timestamp' => now()->toIso8601ZuluString(),
            'app' => config('app.name'),
            'sender_id' => config('rabbitmq.sender_id'),
            'message_id' => $messageId,
            'correlation_id' => $correlationId,
            'nonce' => $nonce,
            'hostname' => gethostname(),
            'payload' => $payload,
        ];

        return new static($data);
    }
}
