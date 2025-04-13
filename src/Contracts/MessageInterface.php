<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use PhpAmqpLib\Message\AMQPMessage;

interface MessageInterface
{
    /** @throws MessageException */
    public static function tryFrom(AMQPMessage $message): static;

    public function routingKey(string $routingKey): static;

    public function options(array $options): static;

    public function getRoutingKey(): string;

    public function getData(): array;

    public function getEvent(): string;

    public function getOptions(): array;

    public function messageId(string $messageId): static;

    public function getMessageId(): string;

    public function correlationId(string $correlationId): static;

    public function getCorrelationId(): string;
}
