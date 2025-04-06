<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use JuniorFontenele\LaravelRabbitMQ\Worker;

class RabbitMQWorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:work
                            {queue=default : The name of the queue to work}
                            {--memory=128 : The memory limit in megabytes}
                            {--timeout=60 : The number of seconds a job may run}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--max-jobs=0 : The number of jobs to process before stopping (0 for unlimited)}
                            {--tries=1 : Number of times to attempt a job before logging it failed}
                            {--once : Only process the next job on the queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start consuming messages from a RabbitMQ queue';

    /**
     * Execute the console command.
     *
     * @param Worker $worker
     * @return int
     */
    public function handle(Worker $worker): int
    {
        $queue = $this->argument('queue');

        $options = [
            'memory_limit' => (int) $this->option('memory'),
            'timeout' => (int) $this->option('timeout'),
            'sleep' => (int) $this->option('sleep'),
            'max_jobs' => $this->option('once') ? 1 : (int) $this->option('max-jobs'),
            'tries' => (int) $this->option('tries'),
            'verbose' => $this->option('verbose'),
            'output' => $this->output,
        ];

        $this->info("Processing jobs from the [{$queue}] queue.");

        try {
            $worker->work($queue, $options);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            $this->error('Failed to start the RabbitMQ worker.');

            return 1;
        }

        return 0;
    }
}
