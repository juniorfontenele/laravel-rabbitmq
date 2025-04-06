<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class Worker
{
    /**
     * The application instance.
     *
     * @var Container
     */
    protected Container $app;

    /**
     * The event dispatcher instance.
     *
     * @var Dispatcher
     */
    protected Dispatcher $events;

    /**
     * The RabbitMQ manager instance.
     *
     * @var RabbitMQManager
     */
    protected RabbitMQManager $manager;

    /**
     * The worker options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * The current job being processed.
     *
     * @var AMQPMessage|null
     */
    protected ?AMQPMessage $currentJob = null;

    /**
     * The last time we checked memory usage.
     *
     * @var int
     */
    protected int $lastMemoryCheck = 0;

    /**
     * The number of jobs processed.
     *
     * @var int
     */
    protected int $jobsProcessed = 0;

    /**
     * Create a new worker instance.
     *
     * @param Container $app
     * @param Dispatcher $events
     * @param RabbitMQManager $manager
     * @return void
     */
    public function __construct(Container $app, Dispatcher $events, RabbitMQManager $manager)
    {
        $this->app = $app;
        $this->events = $events;
        $this->manager = $manager;
    }

    /**
     * Listen for and process jobs from a RabbitMQ queue.
     *
     * @param string $queue
     * @param array<string, mixed> $options
     * @return void
     * @throws RabbitMQException
     */
    public function work(string $queue, array $options = []): void
    {
        $this->options = array_merge(
            config('rabbitmq.worker', []),
            $options
        );

        $queueConfig = config("rabbitmq.queues.{$queue}", []);

        if (empty($queueConfig)) {
            throw new RabbitMQException("Queue [{$queue}] not configured.");
        }

        $exchangeConfig = config("rabbitmq.exchanges.{$queueConfig['exchange']}", []);
        $channel = $this->manager->getConnection()->getChannel($exchangeConfig['connection'] ?? 'default');

        $config = $this->manager->setupChannel($queue, $channel);

        // Setup consumer
        $channel->basic_consume(
            $config['queue']['name'],
            $config['queue']['consumer_tag'],
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($queue) {
                $this->process($message, $queue);
            }
        );

        $this->listenForSignals();

        // Start consuming
        while ($channel->is_consuming()) {
            try {
                $channel->wait(null, true, $this->options['timeout'] ?? 60);

                $this->stopIfNecessary();
            } catch (AMQPTimeoutException $e) {
                // Timeout waiting for a message
                if ($this->shouldSleep()) {
                    $this->sleep();
                }
            } catch (Throwable $e) {
                $this->reportException($e);

                // Sleep briefly before continuing
                $this->sleep(1);
            }
        }
    }

    /**
     * Process an incoming message.
     *
     * @param AMQPMessage $message
     * @param string $queue
     * @return void
     */
    protected function process(AMQPMessage $message, string $queue): void
    {
        try {
            $this->currentJob = $message;

            // Dispatch before processing event
            $this->events->dispatch('rabbitmq.processing', [$message, $queue]);

            // Log the message if verbose mode
            if ($this->options['verbose'] ?? false) {
                $body = $message->getBody();
                $this->app->make('log')->info("Processing message from queue [{$queue}]", [
                    'body' => $body,
                    'properties' => $message->get_properties(),
                ]);

                if ($this->app->runningInConsole() && isset($this->options['output'])) {
                    $this->options['output']->writeln("<info>Processing message:</info> $body");
                }
            }

            $consumer = $this->manager->getConsumer($queue);
            $consumer->process($message);

            // Dispatch after processing event
            $this->events->dispatch('rabbitmq.processed', [$message, $queue]);

            $this->jobsProcessed++;
        } catch (Throwable $e) {
            // Dispatch failed event
            $this->events->dispatch('rabbitmq.failed', [$message, $queue, $e]);

            $this->reportException($e);
        } finally {
            $this->currentJob = null;
        }
    }

    /**
     * Determine if the worker should sleep.
     *
     * @return bool
     */
    protected function shouldSleep(): bool
    {
        return true;
    }

    /**
     * Sleep for the given number of seconds.
     *
     * @param int|null $seconds
     * @return void
     */
    protected function sleep(?int $seconds = null): void
    {
        sleep($seconds ?? $this->options['sleep'] ?? 3);
    }

    /**
     * Stop the worker if necessary.
     *
     * @return void
     */
    protected function stopIfNecessary(): void
    {
        if ($this->shouldQuit()) {
            $this->stop();
        }

        if ($this->memoryExceeded()) {
            $this->stop(12);
        }
    }

    /**
     * Stop the worker.
     *
     * @param int $status
     * @return void
     */
    public function stop(int $status = 0): void
    {
        // Close all connections
        $this->manager->getConnection()->close();

        exit($status);
    }

    /**
     * Determine if the worker should quit.
     *
     * @return bool
     */
    protected function shouldQuit(): bool
    {
        return $this->options['max_jobs'] > 0 && $this->jobsProcessed >= $this->options['max_jobs'];
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @return bool
     */
    protected function memoryExceeded(): bool
    {
        // Check memory every 10 seconds
        if (time() - $this->lastMemoryCheck < 10) {
            return false;
        }

        $this->lastMemoryCheck = time();

        $memoryLimit = $this->options['memory_limit'] ?? 128;

        $usage = memory_get_usage() / 1024 / 1024;

        return $usage >= $memoryLimit;
    }

    /**
     * Report an exception.
     *
     * @param Throwable $e
     * @return void
     */
    protected function reportException(Throwable $e): void
    {
        $this->app->make('log')->error($e);
    }

    /**
     * Listen for signals to stop the worker.
     *
     * @return void
     */
    protected function listenForSignals(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->stop();
            });

            pcntl_signal(SIGINT, function () {
                $this->stop();
            });
        }
    }
}
