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
     * @param AMQPMessage $AMQPMessage
     * @return void
     */
    public function process(AMQPMessage $AMQPMessage): void;

    /**
     * Consume the message.
     *
     * @param AMQPMessage $AMQPMessage
     * @return void
     * @throws Throwable
     */
    public function consume(AMQPMessage $AMQPMessage): void;

    /**
     * Handle message processing failure.
     *
     * @param AMQPMessage $AMQPMessage
     * @param Throwable $exception
     * @return void
     */
    public function failed(AMQPMessage $AMQPMessage, Throwable $exception): void;
}
