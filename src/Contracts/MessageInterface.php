<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureExpiredException;
use PhpAmqpLib\Message\AMQPMessage;

interface MessageInterface
{
    /** @throws MessageException */
    public static function tryFrom(AMQPMessage $AMQPMessage): MessageInterface;

    public function getAMQPMessageInstance(): AMQPMessage;

    public function setAMQPMessageInstance(AMQPMessage $AMQPMessage): MessageInterface;

    public function ack(bool $multiple = false): bool;

    public function nack(bool $multiple = false, bool $requeue = true): bool;

    public function reject(bool $requeue = true): bool;

    public function isRedelivered(): bool;

    public function getConsumerTag(): ?string;

    public function routingKey(string $routingKey): MessageInterface;

    /** @param array<string, mixed> $options */
    public function options(array $options): MessageInterface;

    public function getRoutingKey(): string;

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

    public function getSignature(): string;

    /** @return array<string, mixed> */
    public function getHeader(): array;

    /**
     * @param string $publicKey
     * @throws MessageSignatureException
     * @throws MessageSignatureExpiredException
     * @throws MessageException
     * @return void
     */
    public function validateSignature(string $publicKey): void;

    public function isSignatureValid(string $publicKey): bool;
}
