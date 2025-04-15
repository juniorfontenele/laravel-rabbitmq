<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Unit;

use Illuminate\Container\Container;
use JuniorFontenele\LaravelRabbitMQ\Connection;
use JuniorFontenele\LaravelRabbitMQ\Contracts\ConsumerInterface;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use JuniorFontenele\LaravelRabbitMQ\RabbitMQManager;
use JuniorFontenele\LaravelRabbitMQ\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;

class RabbitMQManagerTest extends TestCase
{
    protected Connection $connection;

    protected Container $container;

    protected RabbitMQManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Mockery::mock(Connection::class);
        $this->container = Mockery::mock(Container::class);
        $this->manager = new RabbitMQManager($this->connection, $this->container);
    }

    public function testRegisterAndGetConsumer(): void
    {
        // Create mock consumer
        $consumer = Mockery::mock(ConsumerInterface::class);

        // Register the consumer
        $this->manager->registerConsumer('test-queue', $consumer);

        // Verify it can be retrieved
        $result = $this->manager->getConsumer('test-queue');

        $this->assertSame($consumer, $result);
    }

    public function testGetConsumerWithClassName(): void
    {
        // Create mock consumer
        $consumer = Mockery::mock(ConsumerInterface::class);

        // Setup container expectation
        $this->container->shouldReceive('make')
            ->with('App\\Consumers\\TestConsumer')
            ->once()
            ->andReturn($consumer);

        // Register consumer class name
        $this->manager->registerConsumer('test-queue', 'App\\Consumers\\TestConsumer');

        // Verify it resolves the class name
        $result = $this->manager->getConsumer('test-queue');

        $this->assertSame($consumer, $result);
    }

    public function testSetupChannelThrowsExceptionForInvalidQueue(): void
    {
        // Mock channel
        $channel = Mockery::mock(AMQPChannel::class);

        // Mock the config to return empty queue config
        config(['rabbitmq.queues.invalid-queue' => []]);

        // Expect exception
        $this->expectException(RabbitMQException::class);
        $this->expectExceptionMessage('Queue [invalid-queue] not configured.');

        $this->manager->setupChannel('invalid-queue', $channel);
    }

    public function testSetupChannelDeclaresExchangeAndQueue(): void
    {
        // Mock channel
        $channel = Mockery::mock(AMQPChannel::class);

        // Setup expectations for channel methods
        $channel->shouldReceive('exchange_declare')
            ->once()
            ->with(
                'test-exchange',
                'direct',
                false,
                true,
                false,
                false,
                false,
                []
            );

        $channel->shouldReceive('queue_declare')
            ->once()
            ->with(
                'test-queue',
                false,
                true,
                false,
                false,
                false,
                []
            );

        $channel->shouldReceive('queue_bind')
            ->once()
            ->with(
                'test-queue',
                'test-exchange',
                'test.routing.key'
            );

        $channel->shouldReceive('basic_qos')
            ->once()
            ->with(0, 1, false);

        // Setup config
        config([
            'rabbitmq.queues.test-queue' => [
                'name' => 'test-queue',
                'exchange' => 'test-exchange',
                'routing_key' => 'test.routing.key',
                'prefetch' => [
                    'size' => 0,
                    'count' => 1,
                ],
            ],
            'rabbitmq.exchanges.test-exchange' => [
                'name' => 'test-exchange',
                'type' => 'direct',
            ],
            'rabbitmq.consumer_tag' => 'test-consumer',
        ]);

        // Call method
        $result = $this->manager->setupChannel('test-queue', $channel);

        // Verify result
        $this->assertEquals('test-consumer', $result['consumer_tag']);
        $this->assertEquals('test-exchange', $result['exchange']['name']);
        $this->assertEquals('test-queue', $result['queue']['name']);
    }

    public function testGetConnection(): void
    {
        $result = $this->manager->getConnection();
        $this->assertSame($this->connection, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
