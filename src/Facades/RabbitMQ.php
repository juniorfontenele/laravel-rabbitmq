<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void publish(string $queue, mixed $data, string $routingKey = '', array<string, mixed> $options = [])
 * @method static void consume(string $queue, \Closure|string|\JuniorFontenele\LaravelRabbitMQ\Contracts\ConsumerInterface $callback, array<string, mixed> $options = [])
 *
 * @see \JuniorFontenele\LaravelRabbitMQ\RabbitMQManager
 */
class RabbitMQ extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \JuniorFontenele\LaravelRabbitMQ\RabbitMQManager::class;
    }
}
