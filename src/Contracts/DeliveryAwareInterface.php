<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

interface DeliveryAwareInterface
{
    public function ack(bool $multiple = false): bool;

    public function nack(bool $multiple = false, bool $requeue = true): bool;

    public function reject(bool $requeue = true): bool;

    public function isRedelivered(): ?bool;

    public function getConsumerTag(): ?string;
}
