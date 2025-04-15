<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use PhpAmqpLib\Message\AMQPMessage;

interface MessageInterface extends DeliveryAwareInterface, RoutableInterface, SignableInterface
{
    /** @throws MessageException */
    public static function tryFrom(AMQPMessage $AMQPMessage): MessageInterface;

    public function getAMQPMessageInstance(): AMQPMessage;

    public function setAMQPMessageInstance(AMQPMessage $AMQPMessage): MessageInterface;

    /** @param array<string, mixed> $options */
    public function options(array $options): MessageInterface;

    /** @return array<string, mixed> */
    public function getData(): array;

    /** @return array<string, mixed> */
    public function getOptions(): array;

    /** @param array<string, mixed> $payload */
    public function payload(array $payload): MessageInterface;

    /** @return array<string, mixed> */
    public function getPayload(): array;

    public function getMessageId(): string;

    public function getCorrelationId(): string;

    public function getNonce(): string;

    /** @return array<string, mixed> */
    public function getHeader(): array;
}
