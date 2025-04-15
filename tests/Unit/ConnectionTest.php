<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests\Unit;

use JuniorFontenele\LaravelRabbitMQ\Connection;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
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

    // Create a partial mock of the Connection class to avoid actual connections
    $this->connectionMock = Mockery::mock(Connection::class, [$this->config])->makePartial();
    $this->connectionMock->shouldAllowMockingProtectedMethods();
});

test('can get default connection', function () {
    // Mock AMQPStreamConnection
    $mockConnection = Mockery::mock(AMQPStreamConnection::class);
    $mockConnection->shouldReceive('isConnected')->andReturn(true);
    $mockConnection->shouldReceive('close');

    // Set expectation on the createConnection method
    $this->connectionMock->shouldReceive('createConnection')
        ->with('default')
        ->once()
        ->andReturn($mockConnection);

    $result = $this->connectionMock->getConnection();

    expect($result)->toBe($mockConnection);
});

test('can get named connection', function () {
    $mockConnection = Mockery::mock(AMQPStreamConnection::class);
    $mockConnection->shouldReceive('isConnected')->andReturn(true);
    $mockConnection->shouldReceive('close');

    $this->connectionMock->shouldReceive('createConnection')
        ->with('ssl_connection')
        ->once()
        ->andReturn($mockConnection);

    $result = $this->connectionMock->getConnection('ssl_connection');

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

    $this->connectionMock->shouldReceive('getConnection')
        ->with('default')
        ->once()
        ->andReturn($mockConnection);

    $result = $this->connectionMock->getChannel();

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

    $this->connectionMock->shouldReceive('createConnection')
        ->with('ssl_connection')
        ->once()
        ->andReturn($mockSslConnection);

    // Get the connection
    $result = $this->connectionMock->getConnection('ssl_connection');

    // Assert the result is our mock
    expect($result)->toBe($mockSslConnection);
});

test('creates non-SSL connection when SSL is disabled', function () {
    // Create a mock connection
    $mockNonSslConnection = Mockery::mock(AMQPStreamConnection::class);
    $mockNonSslConnection->shouldReceive('isConnected')->andReturn(true);
    $mockNonSslConnection->shouldReceive('close');

    $this->connectionMock->shouldReceive('createConnection')
        ->with('default')
        ->andReturn($mockNonSslConnection);

    // Get the connection
    $result = $this->connectionMock->getConnection('default');

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

    $this->connectionMock->shouldReceive('createConnection')
        ->with('default')
        ->andReturn($mockConn1);
    $this->connectionMock->shouldReceive('createConnection')
        ->with('ssl_connection')
        ->andReturn($mockConn2);

    // Create connections and channels
    $this->connectionMock->getConnection('default');
    $this->connectionMock->getConnection('ssl_connection');
    $this->connectionMock->getChannel('default');
    $this->connectionMock->getChannel('ssl_connection');

    // Close all connections
    $this->connectionMock->close();

    // Verify channels and connections arrays are reset
    $reflection = new \ReflectionClass($this->connectionMock);
    $channelsProp = $reflection->getProperty('channels');
    $channelsProp->setAccessible(true);
    $connectionsProp = $reflection->getProperty('connections');
    $connectionsProp->setAccessible(true);

    expect($channelsProp->getValue($this->connectionMock))->toBeEmpty();
    expect($connectionsProp->getValue($this->connectionMock))->toBeEmpty();
});

afterEach(function () {
    Mockery::close();
});
