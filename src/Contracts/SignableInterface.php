<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureException;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\MessageSignatureExpiredException;

interface SignableInterface
{
    public function getSignature(): string;

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
