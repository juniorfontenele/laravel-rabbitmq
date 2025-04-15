<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Tests;

use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use JuniorFontenele\LaravelRabbitMQ\Providers\LaravelRabbitMQServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

use function Orchestra\Testbench\workbench_path;

class TestCase extends OrchestraTestCase
{
    protected $enablesPackageDiscoveries = false;

    protected bool $loadWorkbenchMigrations = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase($this->app);
    }

    /**
     * Define environment setup.
     *
     * @param Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        // Setup default environment configuration
        tap($app['config'], function (Repository $config) {
            $config->set('app.timezone', 'UTC');
            $config->set('app.locale', 'en');
            $config->set('app.fallback_locale', 'en');

            $config->set('database.default', 'sqlite');
            $config->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);

            // Setup default RabbitMQ configuration for tests
            $config->set('rabbitmq.consumer_tag', 'test-consumer');
            $config->set('rabbitmq.worker', [
                'sleep' => 3,
                'timeout' => 60,
                'max_jobs' => 0,
                'memory_limit' => 128,
            ]);
        });
    }

    /**
     * Get package providers.
     *
     * @param Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelRabbitMQServiceProvider::class,
        ];
    }

    /**
     * Set up the database.
     *
     * @param Application $app
     */
    protected function setUpDatabase($app): void
    {
        $schema = $app['db']->connection()->getSchemaBuilder();

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });
    }

    /**
     * Set a mock instance for a given class in the container.
     *
     * @param string|mixed $abstract
     * @param mixed $instance
     * @return $this
     */
    public function instance($abstract, $instance)
    {
        $this->app->instance($abstract, $instance);

        return $this;
    }

    /**
     * Get a test RSA key pair.
     *
     * @return array{private: string, public: string}
     */
    protected function getTestKeyPair(): array
    {
        $rsa = \phpseclib3\Crypt\RSA::createKey(1024);

        return [
            'private' => $rsa->toString('PKCS1'),
            'public' => $rsa->getPublicKey()->toString('PKCS1'),
        ];
    }

    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations(): void
    {
        if (! $this->loadWorkbenchMigrations) {
            return;
        }

        $this->loadMigrationsFrom(
            workbench_path('database/migrations')
        );
    }
}
