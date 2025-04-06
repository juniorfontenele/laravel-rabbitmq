<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Unit;

use JuniorFontenele\LaravelRabbitMQ\Connection;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;

beforeEach(function () {
    $this->config = [
        'default' => [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'ssl' => [
                'enabled' => false,
            ],
        ],
        'ssl_connection' => [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'ssl' => [
                'enabled' => true,
                'cafile' => '/path/to/ca.pem',
                'local_cert' => '/path/to/cert.pem',
                'local_key' => '/path/to/key.pem',
                'verify_peer' => true,
            ],
        ],
    ];
});

test('can get default connection', function () {
    // Mock AMQPStreamConnection
    $mockConnection = Mockery::mock(AMQPStreamConnection::class);
    $mockConnection->shouldReceive('isConnected')->andReturn(true);
    $mockConnection->shouldReceive('close');

    // Use partial mock to override createConnection
    $connection = Mockery::mock(Connection::class, [$this->config])->makePartial();
    $connection->shouldAllowMockingProtectedMethods();
    $connection->shouldReceive('createConnection')
        ->with('default')
        ->once()
        ->andReturn($mockConnection);

    $result = $connection->getConnection();

    expect($result)->toBe($mockConnection);
});

test('can get named connection', function () {
    $mockConnection = Mockery::mock(AMQPStreamConnection::class);
    $mockConnection->shouldReceive('isConnected')->andReturn(true);
    $mockConnection->shouldReceive('close');

    $connection = Mockery::mock(Connection::class, [$this->config])->makePartial();
    $connection->shouldAllowMockingProtectedMethods();
    $connection->shouldReceive('createConnection')
        ->with('ssl_connection')
        ->once()
        ->andReturn($mockConnection);

    $result = $connection->getConnection('ssl_connection');

    expect($result)->toBe($mockConnection);
});

test('can get channel', function () {
    $mockChannel = Mockery::mock(AMQPChannel::class);
    $mockChannel->shouldReceive('is_open')->andReturn(true);
    $mockChannel->shouldReceive('close');

    $mockConnection = Mockery::mock(AbstractConnection::class);
    $mockConnection->shouldReceive('channel')->once()->andReturn($mockChannel);
    $mockConnection->shouldReceive('isConnected')->andReturn(true);
    $mockConnection->shouldReceive('close');

    $connection = Mockery::mock(Connection::class, [$this->config])->makePartial();
    $connection->shouldAllowMockingProtectedMethods();
    $connection->shouldReceive('getConnection')
        ->with('default')
        ->once()
        ->andReturn($mockConnection);

    $result = $connection->getChannel();

    expect($result)->toBe($mockChannel);
});

test('throws exception for non-existent connection', function () {
    $connection = new Connection($this->config);

    $connection->getConnection('non_existent');
})->throws(RabbitMQException::class, 'Connection [non_existent] not configured.');

test('creates SSL connection when SSL is enabled', function () {
    // Mock the connection that will be returned
    $mockSslConnection = Mockery::mock(AbstractConnection::class);
    $mockSslConnection->shouldReceive('isConnected')->andReturn(true);
    $mockSslConnection->shouldReceive('close');

    // Instead of mocking the AMQPConnectionFactory class, use a partial mock on Connection
    $connection = Mockery::mock(Connection::class, [$this->config])->makePartial();
    $connection->shouldAllowMockingProtectedMethods();
    $connection->shouldReceive('createConnection')
        ->with('ssl_connection')
        ->once()
        ->andReturn($mockSslConnection);

    // Get the connection
    $result = $connection->getConnection('ssl_connection');

    // Assert the result is our mock
    expect($result)->toBe($mockSslConnection);
});

test('creates non-SSL connection when SSL is disabled', function () {
    // Create a mock connection
    $mockNonSslConnection = Mockery::mock(AMQPStreamConnection::class);
    $mockNonSslConnection->shouldReceive('isConnected')->andReturn(true);
    $mockNonSslConnection->shouldReceive('close');

    // Use a partial mock of the Connection class
    // The first argument should be the class name, the second argument should be the constructor arguments
    $connection = Mockery::mock(Connection::class, [$this->config])->makePartial();

    // Mock the protected createConnection method to return our mock
    $connection->shouldAllowMockingProtectedMethods();
    $connection->shouldReceive('createConnection')
        ->with('default')
        ->andReturn($mockNonSslConnection);

    // Get the connection
    $result = $connection->getConnection('default');

    // Assert
    expect($result)->toBe($mockNonSslConnection);
});

test('close method closes all connections and channels', function () {
    $mockChannel1 = Mockery::mock(AMQPChannel::class);
    $mockChannel1->shouldReceive('is_open')->once()->andReturn(true);
    $mockChannel1->shouldReceive('close')->once();

    $mockChannel2 = Mockery::mock(AMQPChannel::class);
    $mockChannel2->shouldReceive('is_open')->once()->andReturn(true);
    $mockChannel2->shouldReceive('close')->once();

    $mockConn1 = Mockery::mock(AbstractConnection::class);
    $mockConn1->shouldReceive('channel')->andReturn($mockChannel1);
    $mockConn1->shouldReceive('isConnected')->once()->andReturn(true);
    $mockConn1->shouldReceive('close')->once();

    $mockConn2 = Mockery::mock(AbstractConnection::class);
    $mockConn2->shouldReceive('channel')->andReturn($mockChannel2);
    $mockConn2->shouldReceive('isConnected')->once()->andReturn(true);
    $mockConn2->shouldReceive('close')->once();

    $connection = Mockery::mock(Connection::class, [$this->config])->makePartial();
    $connection->shouldAllowMockingProtectedMethods();
    $connection->shouldReceive('createConnection')
        ->with('default')
        ->andReturn($mockConn1);
    $connection->shouldReceive('createConnection')
        ->with('ssl_connection')
        ->andReturn($mockConn2);

    // Create connections and channels
    $connection->getConnection('default');
    $connection->getConnection('ssl_connection');
    $connection->getChannel('default');
    $connection->getChannel('ssl_connection');

    // Close all connections
    $connection->close();

    // Verify channels and connections arrays are reset
    $reflection = new \ReflectionClass($connection);
    $channelsProp = $reflection->getProperty('channels');
    $channelsProp->setAccessible(true);
    $connectionsProp = $reflection->getProperty('connections');
    $connectionsProp->setAccessible(true);

    expect($channelsProp->getValue($connection))->toBeEmpty();
    expect($connectionsProp->getValue($connection))->toBeEmpty();
});

afterEach(function () {
    Mockery::close();
});
