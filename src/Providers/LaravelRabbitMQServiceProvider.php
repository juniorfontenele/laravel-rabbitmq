<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use JuniorFontenele\LaravelRabbitMQ\Connection;
use JuniorFontenele\LaravelRabbitMQ\Console\Commands\RabbitMQWorkCommand;
use JuniorFontenele\LaravelRabbitMQ\Consumer;
use JuniorFontenele\LaravelRabbitMQ\Contracts\ConsumerInterface;
use JuniorFontenele\LaravelRabbitMQ\Facades\RabbitMQ;
use JuniorFontenele\LaravelRabbitMQ\RabbitMQManager;
use JuniorFontenele\LaravelRabbitMQ\Worker;

class LaravelRabbitMQServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/rabbitmq.php',
            'rabbitmq'
        );

        $this->app->singleton(Connection::class, function ($app) {
            return new Connection($app['config']['rabbitmq.connections']);
        });

        $this->app->singleton(RabbitMQManager::class, function ($app) {
            return new RabbitMQManager(
                $app[Connection::class],
                $app
            );
        });

        $this->app->bind(ConsumerInterface::class, Consumer::class);

        $this->app->singleton(Worker::class, function ($app) {
            return new Worker(
                $app,
                $app['events'],
                $app[RabbitMQManager::class]
            );
        });

        $loader = AliasLoader::getInstance();
        $loader->alias('RabbitMQ', RabbitMQ::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RabbitMQWorkCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../config/rabbitmq.php' => config_path('rabbitmq.php'),
        ], 'config');
    }
}
