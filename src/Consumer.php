<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ;

use Exception;
use Illuminate\Support\Facades\Log;
use JuniorFontenele\LaravelRabbitMQ\Contracts\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class Consumer implements ConsumerInterface
{
    /**
     * Process the message.
     *
     * @param AMQPMessage $AMQPMessage
     * @return void
     * @throws Throwable
     */
    public function process(AMQPMessage $AMQPMessage): void
    {
        try {
            $this->consume($AMQPMessage);
        } catch (Throwable $exception) {
            $this->failed($AMQPMessage, $exception);
        }
    }

    /**
     * Process the message.
     *
     * @param AMQPMessage $AMQPMessage
     * @return void
     * @throws Throwable
     */
    public function consume(AMQPMessage $AMQPMessage): void
    {
        // Default implementation, to be overridden by specific consumers
        $data = json_decode($AMQPMessage->getBody(), true);

        // Process the message here...
        Log::info('Processing RabbitMQ message', ['data' => $data]);

        // Acknowledge the message
        $AMQPMessage->ack();
    }

    /**
     * Handle message processing failure.
     *
     * @param AMQPMessage $AMQPMessage
     * @param Throwable $exception
     * @return void
     */
    public function failed(AMQPMessage $AMQPMessage, Throwable $exception): void
    {
        try {
            // Get message properties
            $properties = $AMQPMessage->get_properties();

            // Get x-death header if exists
            $headers = $properties['application_headers'] ?? new AMQPTable([]);
            $xDeath = $headers->getNativeData()['x-death'] ?? [];

            // Count retries
            $retryCount = 0;

            foreach ($xDeath as $death) {
                if (isset($death['queue']) && $death['queue'] === $AMQPMessage->getRoutingKey()) {
                    $retryCount = $death['count'];

                    break;
                }
            }

            // Get queue configuration
            $queueName = $AMQPMessage->getRoutingKey();
            $queueConfig = config("rabbitmq.queues.{$queueName}", []);
            $retryConfig = $queueConfig['retry'] ?? [];
            $maxRetries = $retryConfig['max_attempts'] ?? 3;

            if ($retryCount < $maxRetries && ($retryConfig['enabled'] ?? true)) {
                // Reject and requeue the message
                $AMQPMessage->reject(true);

                Log::warning('RabbitMQ message processing failed, requeuing.', [
                    'exception' => $exception->getMessage(),
                    'retry_count' => $retryCount,
                    'max_retries' => $maxRetries,
                ]);
            } else {
                // Max retries reached, reject without requeuing
                $AMQPMessage->reject(false);

                Log::error('RabbitMQ message processing failed, max retries reached.', [
                    'exception' => $exception->getMessage(),
                    'retry_count' => $retryCount,
                    'max_retries' => $maxRetries,
                    'message' => $AMQPMessage->getBody(),
                ]);
            }
        } catch (Exception $e) {
            // If error handling fails, just reject the message
            $AMQPMessage->reject(false);

            Log::error('RabbitMQ error handling failed.', [
                'original_exception' => $exception->getMessage(),
                'handling_exception' => $e->getMessage(),
            ]);
        }
    }
}
