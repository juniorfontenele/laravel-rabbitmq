# Laravel RabbitMQ Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/juniorfontenele/laravel-rabbitmq.svg?style=flat-square)](https://packagist.org/packages/juniorfontenele/laravel-rabbitmq)
[![Tests](https://img.shields.io/github/actions/workflow/status/juniorfontenele/laravel-rabbitmq/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/juniorfontenele/laravel-rabbitmq/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/juniorfontenele/laravel-rabbitmq.svg?style=flat-square)](https://packagist.org/packages/juniorfontenele/laravel-rabbitmq)

A robust Laravel package for integrating with RabbitMQ, providing support for multiple connections, exchanges, queues, and consumers. This package simplifies the process of working with RabbitMQ in your Laravel applications.

## Features

- Multiple RabbitMQ connections support
- SSL/TLS connection support
- Easy configuration of exchanges and queues
- Flexible consumer system
- Built-in retry mechanism
- Command-line worker
- Event-driven architecture

## Requirements

- PHP 8.3 or higher
- Laravel 12.0 or higher
- RabbitMQ server
- php-amqplib/php-amqplib ^3.7

## Installation

You can install the package via composer:

```bash
composer require juniorfontenele/laravel-rabbitmq
```

After installing, publish the configuration file:

```bash
php artisan vendor:publish --tag=config --provider="JuniorFontenele\LaravelRabbitMQ\Providers\LaravelRabbitMQServiceProvider"
```

## Configuration

### Basic Environment Configuration

Add the following variables to your `.env` file:

```env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_SSL=false
```

For SSL connections, you can also configure:

```env
RABBITMQ_SSL=true
RABBITMQ_SSL_CAFILE=/path/to/ca.pem
RABBITMQ_SSL_CERTFILE=/path/to/cert.pem
RABBITMQ_SSL_KEYFILE=/path/to/key.pem
RABBITMQ_SSL_VERIFY_PEER=true
```

### Configuration Structure

The published configuration file (`config/rabbitmq.php`) contains sections for:

#### Connections

Define your RabbitMQ server connections:

```php
'connections' => [
    'default' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'ssl' => [
            'enabled' => env('RABBITMQ_SSL', false),
            'cafile' => env('RABBITMQ_SSL_CAFILE', null),
            'local_cert' => env('RABBITMQ_SSL_CERTFILE', null),
            'local_key' => env('RABBITMQ_SSL_KEYFILE', null),
            'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
        ],
    ],
    // Add more connections as needed
]
```

#### Exchanges

Configure your RabbitMQ exchanges:

```php
'exchanges' => [
    'default' => [
        'connection' => 'default',           // Connection to use
        'name' => 'app.default',             // Exchange name
        'type' => 'direct',                  // Type: direct, topic, fanout, headers
        'passive' => false,                  // Don't create, error if doesn't exist
        'durable' => true,                   // Survive broker restart
        'auto_delete' => false,              // Delete when no queues bound
        'internal' => false,                 // No direct publishing
        'arguments' => [],                   // Additional arguments
    ],
]
```

#### Queues

Configure your RabbitMQ queues:

```php
'queues' => [
    'default' => [
        'exchange' => 'default',             // Exchange to bind to
        'name' => 'default_queue',           // Queue name
        'routing_key' => 'default_queue',    // Routing key
        'consumer_tag' => 'consumer_tag',    // Consumer identifier
        'passive' => false,                  // Don't create, error if doesn't exist
        'durable' => true,                   // Survive broker restart
        'exclusive' => false,                // Only one connection can use
        'auto_delete' => false,              // Delete when no consumers
        'arguments' => [],                   // Additional arguments
        'prefetch' => [
            'count' => 1,                    // Messages to prefetch
            'size' => 0,                     // Total size in bytes
        ],
        'retry' => [
            'enabled' => true,               // Enable retry mechanism
            'max_attempts' => 3,             // Maximum retry attempts
            'delay' => 60000,                // Delay between retries (ms)
        ],
    ],
]
```

#### Worker

Configure worker behavior:

```php
'worker' => [
    'memory_limit' => 128,                   // Memory limit in MB
    'timeout' => 60,                         // Wait timeout in seconds
    'sleep' => 3,                            // Sleep when no message (seconds)
    'max_jobs' => 0,                         // Max jobs (0 = unlimited)
    'tries' => 1,                            // Default retry attempts
],
```

## Usage

### Publishing Messages

You can publish messages using the RabbitMQ facade:

```php
use JuniorFontenele\LaravelRabbitMQ\Facades\RabbitMQ;

// Basic publishing
RabbitMQ::publish('default', [
    'id' => 1,
    'message' => 'Hello, RabbitMQ!'
]);

// Publishing with options
RabbitMQ::publish($exchangeName, $data, $routingKey, [
    'message_id' => uniqid(),
    'correlation_id' => $correlationId,
    'headers' => ['priority' => 'high']
]);
```

### Consuming Messages

#### Creating a Consumer

Create a custom consumer by extending the base `Consumer` class:

```php
<?php

namespace App\System\RabbitMQ\Consumers;

use JuniorFontenele\LaravelRabbitMQ\Consumer;
use PhpAmqpLib\Message\AMQPMessage;

class NotificationsConsumer extends Consumer
{
    /**
     * Process the message.
     *
     * @param AMQPMessage $message
     * @return void
     */
    public function consume(AMQPMessage $message): void
    {
        $data = json_decode($message->getBody(), true);

        // Process the message
        // ...

        // Acknowledge the message after successful processing
        $message->ack();
    }
}
```

#### Registering a Consumer

Register your consumer in a service provider:

```php
<?php

namespace App\Providers;

use App\System\RabbitMQ\Consumers\NotificationsConsumer;
use Illuminate\Support\ServiceProvider;
use JuniorFontenele\LaravelRabbitMQ\RabbitMQManager;

class AppServiceProvider extends ServiceProvider
{
    public function boot(RabbitMQManager $manager)
    {
        // Register consumer for notifications queue
        $manager->registerConsumer('notifications', NotificationsConsumer::class);
    }
}
```

#### Starting a Worker

You can start a worker via command line:

```bash
# Basic usage
php artisan rabbitmq:work default

# With options
php artisan rabbitmq:work notifications \
    --memory=256 \
    --timeout=120 \
    --sleep=5 \
    --max-jobs=1000 \
    --tries=3 \
    --once
```

Or programmatically:

```php
use JuniorFontenele\LaravelRabbitMQ\Worker;

$worker = app(Worker::class);
$worker->work('notifications', [
    'memory_limit' => 256,
    'timeout' => 120,
    'max_jobs' => 1000,
    'verbose' => true
]);
```

### Listening for Events

The package dispatches the following events:

- `rabbitmq.processing`: Before processing a message
- `rabbitmq.processed`: After successful processing
- `rabbitmq.failed`: When processing fails

You can listen for these events in your `EventServiceProvider`:

```php
protected $listen = [
    'rabbitmq.processing' => [
        \App\Listeners\LogProcessingMessage::class,
    ],
    'rabbitmq.processed' => [
        \App\Listeners\LogProcessedMessage::class,
    ],
    'rabbitmq.failed' => [
        \App\Listeners\LogFailedMessage::class,
    ],
];
```

Or using the event facade:

```php
use Illuminate\Support\Facades\Event;

Event::listen('rabbitmq.failed', function($message, $queue, $exception) {
    Log::error("Failed to process message", [
        'queue' => $queue,
        'error' => $exception->getMessage()
    ]);
});
```

### Error Handling

The package includes a built-in retry mechanism:

1. When a consumer throws an exception, the `failed` method is called
2. The message retry count is checked against the configured maximum attempts
3. If retries are available, the message is requeued
4. If max retries are reached, the message is rejected (not requeued)
5. A `rabbitmq.failed` event is dispatched

You can customize this behavior by overriding the `failed` method in your consumer:

```php
public function failed(AMQPMessage $message, Throwable $exception): void
{
    // Custom failure handling
    Log::error("Custom failure handling", [
        'message' => $message->getBody(),
        'exception' => $exception->getMessage()
    ]);

    // Call parent implementation or handle completely custom
    parent::failed($message, $exception);
}
```

## Best Practices

1. Use durable exchanges and queues for important messages
2. Configure prefetch count appropriately for your workload
3. Implement proper error handling in consumers
4. Use meaningful routing keys for better message routing
5. Configure retry policies based on your use case
6. Monitor memory usage and set appropriate limits
7. Use correlation IDs to track related messages
8. Implement dead letter exchanges for failed messages

## Testing

The package includes tests that you can run:

```bash
composer test
```

You can also run tests with coverage:

```bash
composer test-coverage
```

To test your own implementation, you can mock the `RabbitMQManager` in your tests:

```php
use JuniorFontenele\LaravelRabbitMQ\RabbitMQManager;
use JuniorFontenele\LaravelRabbitMQ\Facades\RabbitMQ;

// Mock the RabbitMQManager
$this->mock(RabbitMQManager::class, function ($mock) {
    $mock->shouldReceive('publish')
        ->once()
        ->with('notifications', ['test' => 'data'], [])
        ->andReturn(null);
});

// Call your code that uses the RabbitMQ facade
RabbitMQ::publish('notifications', ['test' => 'data']);
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Junior Fontenele](https://github.com/juniorfontenele)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
