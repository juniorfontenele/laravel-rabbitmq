<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ;

use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Connection
{
    /**
     * The connections configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * The active connections.
     *
     * @var array<string, mixed>
     */
    protected array $connections = [];

    /**
     * The active channels.
     *
     * @var array<string, mixed>
     */
    protected array $channels = [];

    /**
     * Create a new connection instance.
     *
     * @param array<string, mixed> $config
     * @return void
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a connection instance.
     *
     * @param string $name
     * @return AbstractConnection
     */
    public function getConnection(string $name = 'default'): AbstractConnection
    {
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Get a channel instance.
     *
     * @param string $name
     * @return AMQPChannel
     */
    public function getChannel(string $name = 'default'): AMQPChannel
    {
        if (! isset($this->channels[$name])) {
            $this->channels[$name] = $this->getConnection($name)->channel();
        }

        return $this->channels[$name];
    }

    /**
     * Create a new connection.
     *
     * @param string $name
     * @return AbstractConnection
     *
     * @throws RabbitMQException
     */
    protected function createConnection(string $name): AbstractConnection
    {
        $config = $this->getConnectionConfig($name);

        if ($config['ssl']['enabled']) {
            $connectionConfig = new AMQPConnectionConfig();
            $connectionConfig->setHost($config['host']);
            $connectionConfig->setPort((int) $config['port']);
            $connectionConfig->setUser($config['user']);
            $connectionConfig->setPassword($config['password']);
            $connectionConfig->setVhost($config['vhost']);
            $connectionConfig->setIsSecure(true);
            $connectionConfig->setSslCaCert($config['ssl']['cafile']);
            $connectionConfig->setSslCert($config['ssl']['local_cert']);
            $connectionConfig->setSslKey($config['ssl']['local_key']);
            $connectionConfig->setSslVerify($config['ssl']['verify_peer']);

            return AMQPConnectionFactory::create($connectionConfig);
        }

        return new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost']
        );
    }

    /**
     * Get the connection configuration.
     *
     * @param string $name
     * @return array<string, mixed>
     *
     * @throws RabbitMQException
     */
    protected function getConnectionConfig(string $name): array
    {
        if (! isset($this->config[$name])) {
            throw new RabbitMQException("Connection [{$name}] not configured.");
        }

        return $this->config[$name];
    }

    /**
     * Close all connections.
     *
     * @return void
     */
    public function close(): void
    {
        foreach ($this->channels as $channel) {
            if ($channel->is_open()) {
                $channel->close();
            }
        }

        foreach ($this->connections as $connection) {
            if ($connection->isConnected()) {
                $connection->close();
            }
        }

        $this->channels = [];
        $this->connections = [];
    }

    /**
     * Close the connection when the object is destroyed.
     */
    public function __destruct()
    {
        $this->close();
    }
}
