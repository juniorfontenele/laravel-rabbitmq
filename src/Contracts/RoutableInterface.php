<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

interface RoutableInterface
{
    public function routingKey(string $routingKey): MessageInterface;

    public function getRoutingKey(): string;
}
