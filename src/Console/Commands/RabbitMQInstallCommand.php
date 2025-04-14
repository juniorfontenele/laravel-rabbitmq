<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use JuniorFontenele\LaravelRabbitMQ\Providers\LaravelRabbitMQServiceProvider;

class RabbitMQInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:install
                            {--force : Force the installation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Laravel RabbitMQ Package';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Installing Laravel RabbitMQ Package...');

        $this->callSilent('vendor:publish', [
            '--tag' => 'config',
            '--force' => $this->option('force'),
            '--provider' => LaravelRabbitMQServiceProvider::class,
        ]);

        $this->info('Laravel RabbitMQ Package installed successfully.');
        $this->info('Please check the configuration file at config/rabbitmq.php');
        $this->info('and update the settings according to your RabbitMQ server configuration.');
    }
}
