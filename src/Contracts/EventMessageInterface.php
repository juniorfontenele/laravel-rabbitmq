<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use PhpAmqpLib\Message\AMQPMessage;

interface EventMessageInterface extends MessageInterface
{
    /** @throws MessageException */
    public static function tryFrom(AMQPMessage $AMQPMessage): EventMessageInterface;

    /**
     * Create a new event message.
     *
     * @param string $event
     * @param array<string, mixed> $payload
     * @param string $correlationId
     * @return EventMessageInterface
     */
    public static function make(string $event, array $payload = [], string $correlationId = ''): EventMessageInterface;

    /**
     * Get the event name.
     *
     * @return string
     */
    public function getEvent(): string;

    public function setAMQPMessageInstance(AMQPMessage $AMQPMessage): EventMessageInterface;

    public function routingKey(string $routingKey): EventMessageInterface;

    public function options(array $options): EventMessageInterface;

    public function payload(array $payload): EventMessageInterface;
}
