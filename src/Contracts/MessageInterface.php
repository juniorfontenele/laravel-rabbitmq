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
    public static function tryFrom(AMQPMessage $message): static;

    public function routingKey(string $routingKey): static;

    /** @param array<string, mixed> $options */
    public function options(array $options): static;

    public function getRoutingKey(): string;

    /** @return array<string, mixed> */
    public function getData(): array;

    public function getEvent(): string;

    /** @return array<string, mixed> */
    public function getOptions(): array;

    public function getMessageId(): string;

    public function getCorrelationId(): string;

    /** @param array<string, mixed> $payload */
    public function payload(array $payload): static;

    /** @return array<string, mixed> */
    public function getPayload(): array;

    public function getNonce(): string;

    public function getSignature(): string;

    public function getSigningAlgorithm(): string;

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
