<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Messages;

use Illuminate\Support\Str;
use JuniorFontenele\LaravelRabbitMQ\Contracts\EventMessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use PhpAmqpLib\Message\AMQPMessage;

class EventMessage extends AbstractMessage implements EventMessageInterface
{
    /**
     * @var string The event name
     */
    protected string $event;

    /**
     * EventMessage constructor.
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The message data
     */
    final protected function __construct(string $event, array $data)
    {
        $this->event = $event;
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
     * @throws MessageException
     * @return EventMessageInterface
     */
    public static function tryFrom(AMQPMessage $AMQPMessage): EventMessageInterface
    {
        $data = json_decode($AMQPMessage->getBody(), true, 512, JSON_THROW_ON_ERROR);

        static::validateMessageData($data);

        if (! isset($data['event'])) {
            throw new MessageException('Event not found in message data.');
        }

        $message = new static(
            event: $data['event'],
            data: $data
        );

        return $message->routingKey($AMQPMessage->getRoutingKey() ?? '')
            ->options($AMQPMessage->get_properties())
            ->setAMQPMessageInstance($AMQPMessage);
    }

    /**
     * Create a new event message instance.
     *
     * @param string $event
     * @param array<string, mixed> $payload
     * @param string $correlationId
     * @return EventMessageInterface
     */
    public static function make(string $event, array $payload = [], string $correlationId = ''): EventMessageInterface
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
            'event' => $event,
            'payload' => $payload,
        ];

        return new static($event, $data);
    }

    /**
     * Get the event name.
     *
     * @return string The event name
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    public function setAMQPMessageInstance(AMQPMessage $AMQPMessage): EventMessageInterface
    {
        parent::setAMQPMessageInstance($AMQPMessage);

        return $this;
    }

    public function routingKey(string $routingKey): EventMessageInterface
    {
        parent::routingKey($routingKey);

        return $this;
    }

    public function options(array $options): EventMessageInterface
    {
        parent::options($options);

        return $this;
    }

    public function payload(array $payload): EventMessageInterface
    {
        parent::payload($payload);

        return $this;
    }

    public function senderId(string $senderId): EventMessageInterface
    {
        parent::senderId($senderId);

        return $this;
    }
}
