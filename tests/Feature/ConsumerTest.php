<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Feature;

use Exception;
use Illuminate\Support\Facades\Log;
use JuniorFontenele\LaravelRabbitMQ\Consumer;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class ConsumerTest extends TestCase
{
    protected $consumer;

    protected $message;

    protected $queueConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Log facade
        Log::shouldReceive('info')->byDefault();
        Log::shouldReceive('warning')->byDefault();
        Log::shouldReceive('error')->byDefault();

        // Create consumer instance
        $this->consumer = new Consumer();

        // Create a basic AMQPMessage mock
        $this->message = $this->createMock(AMQPMessage::class);
        $this->message->method('getBody')->willReturn(json_encode(['test' => 'data']));
        $this->message->method('getRoutingKey')->willReturn('notifications');

        // Set default queue config
        $this->queueConfig = [
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
            ],
        ];

        // Configure queue retry settings in the config
        config(['rabbitmq.queues.notifications' => $this->queueConfig]);
    }

    public function testProcessMessageSuccessfully()
    {
        // Setup expectations
        $this->message->expects($this->once())
            ->method('ack');

        Log::shouldReceive('info')
            ->once()
            ->with('Processing RabbitMQ message', ['data' => ['test' => 'data']]);

        // Execute the method
        $this->consumer->process($this->message);
    }

    public function testFailedMessageWithRetry()
    {
        // Setup a consumer that throws an exception during processing
        $exceptionConsumer = $this->createExceptionConsumer();

        // Configure message properties for retry
        $properties = [
            'application_headers' => new AMQPTable([
                'x-death' => [
                    ['queue' => 'notifications', 'count' => 1],
                ],
            ]),
        ];

        $this->message->method('get_properties')
            ->willReturn($properties);

        // Expect message to be requeued (reject with requeue=true)
        $this->message->expects($this->once())
            ->method('reject')
            ->with(true);

        // Execute the process method which will fail
        $exceptionConsumer->process($this->message);
    }

    public function testFailedMessageMaxRetriesReached()
    {
        // Setup a consumer that throws an exception during processing
        $exceptionConsumer = $this->createExceptionConsumer();

        // Configure message properties for max retries
        $properties = [
            'application_headers' => new AMQPTable([
                'x-death' => [
                    ['queue' => 'notifications', 'count' => 3],
                ],
            ]),
        ];

        $this->message->method('get_properties')
            ->willReturn($properties);

        // Expect message to be rejected without requeuing
        $this->message->expects($this->once())
            ->method('reject')
            ->with(false);

        // Execute the process method which will fail
        $exceptionConsumer->process($this->message);
    }

    /**
     * Create a consumer that throws an exception during processing.
     *
     * @return Consumer
     */
    private function createExceptionConsumer(): Consumer
    {
        return new class extends Consumer
        {
            public function consume(AMQPMessage $AMQPMessage): void
            {
                throw new Exception('Test exception');
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
