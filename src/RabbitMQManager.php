<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use JuniorFontenele\LaravelRabbitMQ\Contracts\ConsumerInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQManager
{
    /**
     * The connection instance.
     *
     * @var Connection
     */
    protected Connection $connection;

    /**
     * The application container instance.
     *
     * @var Container
     */
    protected Container $app;

    /**
     * Registered consumers.
     *
     * @var array<string, ConsumerInterface|string>
     */
    protected array $consumers = [];

    /**
     * Create a new RabbitMQ manager instance.
     *
     * @param Connection $connection
     * @param Container $app
     * @return void
     */
    public function __construct(Connection $connection, Container $app)
    {
        $this->connection = $connection;
        $this->app = $app;
    }

    /**
     * Register a consumer.
     *
     * @param string $queue
     * @param ConsumerInterface|string $consumer
     * @return void
     */
    public function registerConsumer(string $queue, ConsumerInterface|string $consumer): void
    {
        $this->consumers[$queue] = $consumer;
    }

    /**
     * Get a consumer instance.
     *
     * @param string $queue
     * @return ConsumerInterface
     */
    public function getConsumer(string $queue): ConsumerInterface
    {
        if (isset($this->consumers[$queue])) {
            return $this->resolveConsumerInstance($this->consumers[$queue]);
        }

        // Try to resolve a specific consumer class based on queue name
        $class = "App\\System\\RabbitMQ\\Consumers\\" . Str::studly($queue) . 'Consumer';

        if (class_exists($class)) {
            return $this->app->make($class);
        }

        // Fall back to the default consumer
        return $this->app->make(ConsumerInterface::class);
    }

    /**
     * Get all registered consumers.
     *
     * @return array<string, ConsumerInterface|string>
     */
    public function getConsumers(): array
    {
        return $this->consumers;
    }

    /**
     * Resolve a consumer instance.
     *
     * @param ConsumerInterface|string $consumer
     * @return ConsumerInterface
     */
    protected function resolveConsumerInstance(ConsumerInterface|string $consumer): ConsumerInterface
    {
        if (is_string($consumer)) {
            return $this->app->make($consumer);
        }

        return $consumer;
    }

    /**
     * Setup the RabbitMQ channel for a queue.
     *
     * @param string $queueName
     * @param AMQPChannel $channel
     * @return array<string, mixed>
     * @throws RabbitMQException
     */
    public function setupChannel(string $queueName, AMQPChannel $channel): array
    {
        $queueConfig = config("rabbitmq.queues.{$queueName}");

        if (empty($queueConfig)) {
            throw new RabbitMQException("Queue [{$queueName}] not configured.");
        }

        $exchangeKey = $queueConfig['exchange'] ?? 'default';
        $exchangeConfig = config("rabbitmq.exchanges.{$exchangeKey}");

        if (empty($exchangeConfig)) {
            throw new RabbitMQException("Exchange [{$exchangeKey}] not configured.");
        }

        // Declare the exchange
        $channel->exchange_declare(
            $exchangeConfig['name'],
            $exchangeConfig['type'],
            $exchangeConfig['passive'] ?? false,
            $exchangeConfig['durable'] ?? true,
            $exchangeConfig['auto_delete'] ?? false,
            $exchangeConfig['internal'] ?? false,
            false, // nowait
            $exchangeConfig['arguments'] ?? []
        );

        // Declare the queue
        $channel->queue_declare(
            $queueConfig['name'],
            $queueConfig['passive'] ?? false,
            $queueConfig['durable'] ?? true,
            $queueConfig['exclusive'] ?? false,
            $queueConfig['auto_delete'] ?? false,
            false, // nowait
            $queueConfig['arguments'] ?? []
        );

        // Bind the queue to the exchange
        $channel->queue_bind(
            $queueConfig['name'],
            $exchangeConfig['name'],
            $queueConfig['routing_key'] ?? ''
        );

        // Set QoS settings
        $channel->basic_qos(
            $queueConfig['prefetch']['size'] ?? 0,
            $queueConfig['prefetch']['count'] ?? 1,
            false
        );

        return ['queue' => $queueConfig, 'exchange' => $exchangeConfig];
    }

    /**
     * Publish a message to the specified queue.
     *
     * @param string $queueName
     * @param mixed $data
     * @param array<string, mixed> $options
     * @return void
     * @throws RabbitMQException
     */
    public function publish(string $queueName, $data, array $options = []): void
    {
        $queueConfig = config("rabbitmq.queues.{$queueName}");
        $exchangeConfig = config("rabbitmq.exchanges.{$queueConfig['exchange']}");
        $connectionName = $exchangeConfig['connection'] ?? 'default';

        $channel = $this->connection->getChannel($connectionName);

        $config = $this->setupChannel($queueName, $channel);

        // Prepare message properties
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        // Add custom headers if provided
        if (! empty($options['headers'])) {
            $properties['application_headers'] = new AMQPTable($options['headers']);
        }

        // Add message ID if provided
        if (! empty($options['message_id'])) {
            $properties['message_id'] = $options['message_id'];
        }

        // Add correlation ID if provided
        if (! empty($options['correlation_id'])) {
            $properties['correlation_id'] = $options['correlation_id'];
        }

        // Prepare message body
        $messageBody = is_string($data) ? $data : (string) json_encode($data);

        // Create and publish message
        $message = new AMQPMessage($messageBody, $properties);

        $routingKey = $options['routing_key'] ?? $config['queue']['routing_key'] ?? '';

        $channel->basic_publish(
            $message,
            $config['exchange']['name'],
            $routingKey,
        );
    }

    /**
     * Consume messages from the specified queue.
     *
     * @param string $queueName
     * @param Closure|string|ConsumerInterface $callback
     * @param array<string, mixed> $options
     * @return void
     * @throws RabbitMQException
     */
    public function consume(string $queueName, Closure|string|ConsumerInterface $callback, array $options = []): void
    {
        $queueConfig = config("rabbitmq.queues.{$queueName}");
        $exchangeConfig = config("rabbitmq.exchanges.{$queueConfig['exchange']}");
        $connectionName = $exchangeConfig['connection'] ?? 'default';

        $channel = $this->connection->getChannel($connectionName);

        $config = $this->setupChannel($queueName, $channel);

        // Prepare the consumer callback
        if ($callback instanceof Closure) {
            $consumerCallback = function (AMQPMessage $message) use ($callback) {
                $callback($message);
            };
        } elseif ($callback instanceof ConsumerInterface) {
            $consumerCallback = function (AMQPMessage $message) use ($callback) {
                $callback->process($message);
            };
        } elseif (class_exists($callback)) {
            $instance = app()->make($callback);
            $consumerCallback = function (AMQPMessage $message) use ($instance) {
                $instance->process($message);
            };
        } else {
            throw new RabbitMQException("Invalid consumer callback provided.");
        }

        // Setup consumer
        $consumerTag = $options['consumer_tag'] ?? $config['queue']['consumer_tag'] ?? 'consumer_' . uniqid();

        $channel->basic_consume(
            $config['queue']['name'],
            $consumerTag,
            false,
            false,
            false,
            false,
            $consumerCallback
        );

        // Start consuming
        $timeout = $options['timeout'] ?? 0;
        $maxMessages = $options['max_messages'] ?? 0;
        $messagesProcessed = 0;

        while ($channel->is_consuming() && ($maxMessages === 0 || $messagesProcessed < $maxMessages)) {
            $channel->wait(null, true, $timeout);
            $messagesProcessed++;
        }
    }

    /**
     * Get the connection instance.
     *
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
