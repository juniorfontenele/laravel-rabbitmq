<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

interface EventMessageInterface extends MessageInterface
{
    public function getEvent(): string;
}
