<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Messages;

use JuniorFontenele\LaravelRabbitMQ\Contracts\MessageInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use PhpAmqpLib\Message\AMQPMessage;

class EventMessage implements MessageInterface
{
    protected function __construct(
        protected string $event,
        protected array $data = [],
        protected string $routingKey = '',
        protected array $options = [],
    ) {
        //
    }

    public static function tryFrom(AMQPMessage $message): static
    {
        $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($data['event'])) {
            throw new MessageException('Event not found in message data.');
        }

        $eventMessage = new static(
            event: $data['event'],
            data: $data,
            routingKey: $message->getRoutingKey(),
            options: $message->get_properties()
        );

        if ($message->has('message_id')) {
            $eventMessage->messageId($message->get('message_id'));
        }

        if ($message->has('correlation_id')) {
            $eventMessage->correlationId($message->get('correlation_id'));
        }

        return $eventMessage;
    }

    public static function make(string $event, array $payload = []): static
    {
        $data = [
            'timestamp' => now()->toIso8601String(),
            'app' => config('app.name'),
            'hostname' => gethostname(),
            'event' => $event,
            'payload' => $payload,
        ];

        return new static($event, $data);
    }

    public function getTimestamp(): ?string
    {
        return $this->data['timestamp'] ?? null;
    }

    public function routingKey(string $routingKey): static
    {
        $this->routingKey = $routingKey;

        return $this;
    }

    public function payload(array $payload): static
    {
        $this->data['payload'] = $payload;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->data['payload'];
    }

    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function messageId(string $messageId): static
    {
        $this->options['message_id'] = $messageId;

        return $this;
    }

    public function getMessageId(): string
    {
        return $this->options['message_id'] ?? '';
    }

    public function correlationId(string $correlationId): static
    {
        $this->options['correlation_id'] = $correlationId;

        return $this;
    }

    public function getCorrelationId(): string
    {
        return $this->options['correlation_id'] ?? '';
    }
}
