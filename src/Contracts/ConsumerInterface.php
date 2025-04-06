<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Contracts;

use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

interface ConsumerInterface
{
    /**
     * Process the message.
     *
     * @param AMQPMessage $message
     * @return void
     */
    public function process(AMQPMessage $message): void;

    /**
     * Handle message processing failure.
     *
     * @param AMQPMessage $message
     * @param Throwable $exception
     * @return void
     */
    public function failed(AMQPMessage $message, Throwable $exception): void;
}
