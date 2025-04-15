<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Feature;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use JuniorFontenele\LaravelRabbitMQ\RabbitMQManager;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;
use JuniorFontenele\LaravelRabbitMQ\Worker;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;

class WorkerTest extends TestCase
{
    // Remove the type hint from app property since it's already defined in parent class
    protected $appContainer;

    protected Dispatcher $events;

    protected RabbitMQManager $manager;

    protected Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->appContainer = Mockery::mock(Container::class);
        $this->events = Mockery::mock(Dispatcher::class);
        $this->manager = Mockery::mock(RabbitMQManager::class);

        // Setup common expectations
        $this->events->shouldReceive('dispatch')->byDefault();

        // Create the worker with mocks
        $this->worker = new class ($this->appContainer, $this->events, $this->manager) extends Worker
        {
            // Override signal registration for tests
            public function registerSignals(): void
            {
                // Disable signal handling in tests
            }
        };
    }

    public function testShouldQuitWhenMaxJobsReached(): void
    {
        // Access protected method using reflection
        $reflection = new \ReflectionClass($this->worker);
        $shouldQuitMethod = $reflection->getMethod('shouldQuit');
        $shouldQuitMethod->setAccessible(true);

        $optionsProperty = $reflection->getProperty('options');
        $optionsProperty->setAccessible(true);
        $optionsProperty->setValue($this->worker, ['max_jobs' => 5]);

        $jobsProcessedProperty = $reflection->getProperty('jobsProcessed');
        $jobsProcessedProperty->setAccessible(true);

        // Test should not quit yet
        $jobsProcessedProperty->setValue($this->worker, 3);
        $this->assertFalse($shouldQuitMethod->invoke($this->worker));

        // Test should quit when jobs processed equals max_jobs
        $jobsProcessedProperty->setValue($this->worker, 5);
        $this->assertTrue($shouldQuitMethod->invoke($this->worker));

        // Test should quit when jobs processed exceeds max_jobs
        $jobsProcessedProperty->setValue($this->worker, 6);
        $this->assertTrue($shouldQuitMethod->invoke($this->worker));
    }

    public function testMemoryExceededWhenOverLimit(): void
    {
        // Access protected method using reflection
        $reflection = new \ReflectionClass($this->worker);
        $memoryExceededMethod = $reflection->getMethod('memoryExceeded');
        $memoryExceededMethod->setAccessible(true);

        $optionsProperty = $reflection->getProperty('options');
        $optionsProperty->setAccessible(true);
        $optionsProperty->setValue($this->worker, ['memory_limit' => 999999]); // Very high to ensure test doesn't fail

        $lastMemoryCheckProperty = $reflection->getProperty('lastMemoryCheck');
        $lastMemoryCheckProperty->setAccessible(true);
        $lastMemoryCheckProperty->setValue($this->worker, 0); // Ensure check happens

        // Test memory not exceeded with high limit
        $this->assertFalse($memoryExceededMethod->invoke($this->worker));

        // Test memory exceeded with very low limit
        $optionsProperty->setValue($this->worker, ['memory_limit' => 1]); // 1MB is almost certainly below current usage
        $lastMemoryCheckProperty->setValue($this->worker, 0);
        $this->assertTrue($memoryExceededMethod->invoke($this->worker));
    }

    public function testProcessUpdatesJobsProcessedCount(): void
    {
        // Create mocks
        $message = Mockery::mock(AMQPMessage::class);
        $log = Mockery::mock(\Illuminate\Log\LogManager::class);
        $consumer = Mockery::mock(\JuniorFontenele\LaravelRabbitMQ\Contracts\ConsumerInterface::class);

        // Setup expectations
        $this->appContainer->shouldReceive('make')->with('log')->andReturn($log);
        $log->shouldReceive('info')->byDefault();
        $log->shouldReceive('error')->byDefault();

        // Expect the manager to get the consumer
        $this->manager->shouldReceive('getConsumer')
            ->with('test-queue')
            ->andReturn($consumer);

        // Expect the consumer to process the message
        $consumer->shouldReceive('process')
            ->with($message);

        // Setup event expectations
        $this->events->shouldReceive('dispatch')
            ->with('rabbitmq.processing', [$message, 'test-queue'])
            ->once();

        $this->events->shouldReceive('dispatch')
            ->with('rabbitmq.processed', [$message, 'test-queue'])
            ->once();

        // Get initial jobs processed count
        $reflection = new \ReflectionClass($this->worker);
        $jobsProcessedProperty = $reflection->getProperty('jobsProcessed');
        $jobsProcessedProperty->setAccessible(true);
        $initialCount = $jobsProcessedProperty->getValue($this->worker);

        // Access protected method using reflection
        $processMethod = $reflection->getMethod('process');
        $processMethod->setAccessible(true);

        // Call process
        $processMethod->invoke($this->worker, $message, 'test-queue');

        // Verify jobs processed was incremented
        $this->assertEquals($initialCount + 1, $jobsProcessedProperty->getValue($this->worker));
    }

    public function testWorkThrowsExceptionForInvalidQueue(): void
    {
        // Setup expectations for config
        $this->appContainer->shouldReceive('make')->with('config')->andReturn(
            Mockery::mock(\Illuminate\Config\Repository::class)
        );

        // Configure app
        config(['rabbitmq.queues.invalid-queue' => []]);

        $this->expectException(RabbitMQException::class);
        $this->expectExceptionMessage('Queue [invalid-queue] not configured.');

        $this->worker->work('invalid-queue');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
