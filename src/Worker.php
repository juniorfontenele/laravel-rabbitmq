<?php

declare(strict_types = 1);

namespace JuniorFontenele\LaravelRabbitMQ;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use JuniorFontenele\LaravelRabbitMQ\Exceptions\RabbitMQException;
use PhpAmqpLib\Channel\AMQPChannel;
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
     * The RabbitMQ channel instance.
     *
     * @var AMQPChannel
     */
    protected AMQPChannel $channel;

    protected string $consumerTag;

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

    protected bool $restart = false;

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

        $this->registerSignals();
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
        if ($this->restart) {
            $this->events->dispatch('rabbitmq.worker.restarting', [$queue]);
            $this->restart = false;
        } else {
            $this->events->dispatch('rabbitmq.worker.starting', [$queue]);
        }

        $this->options = array_merge(
            config('rabbitmq.worker', []),
            $options
        );

        $queueConfig = config("rabbitmq.queues.{$queue}", []);

        if (empty($queueConfig)) {
            throw new RabbitMQException("Queue [{$queue}] not configured.");
        }

        $exchangeConfig = config("rabbitmq.exchanges.{$queueConfig['exchange']}", []);
        $this->channel = $this->manager->getConnection()->getChannel($exchangeConfig['connection'] ?? 'default');

        $config = $this->manager->setupChannel($queue, $this->channel);

        $this->consumerTag = $config['consumer_tag'];

        // Setup consumer
        $this->channel->basic_consume(
            $config['queue']['name'],
            $this->consumerTag,
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($queue) {
                $this->process($message, $queue);
            }
        );

        $this->events->dispatch('rabbitmq.worker.started', [$queue]);
        // Start consuming
        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, $this->options['timeout'] ?? 60);

                $this->stopIfNecessary();
            } catch (AMQPTimeoutException $e) {
                $this->events->dispatch('rabbitmq.worker.timeout', [$queue, $e, $this->shouldSleep()]);

                // Timeout waiting for a message
                if ($this->shouldSleep()) {
                    $this->sleep();
                }
            } catch (Throwable $e) {
                $this->events->dispatch('rabbitmq.worker.error', [$queue, $e]);
                $this->reportException($e);

                // Sleep briefly before continuing
                $this->sleep(1);
            }
        }

        $this->events->dispatch('rabbitmq.worker.stopped', [$queue]);
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

            $this->processMessage($message, $queue);

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

    /* Process the message with the appropriate consumer.
    *
    * @param AMQPMessage $message
    * @param string $queue
    * @return void
    */
    protected function processMessage(AMQPMessage $message, string $queue): void
    {
        // Log the message if verbose mode
        if ($this->options['verbose'] ?? false) {
            $this->logVerboseMessage($message, $queue);
        }

        $consumer = $this->manager->getConsumer($queue);
        $consumer->process($message);
    }

    /**
     * Log verbose message details.
     *
     * @param AMQPMessage $message
     * @param string $queue
     * @return void
     */
    protected function logVerboseMessage(AMQPMessage $message, string $queue): void
    {
        $body = $message->getBody();
        $this->app->make('log')->info("Processing message from queue [{$queue}]", [
            'body' => $body,
            'properties' => $message->get_properties(),
        ]);

        if ($this->app->runningInConsole() && isset($this->options['output'])) {
            $this->options['output']->writeln("<info>Processing message:</info> $body");
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
    public function registerSignals(): void
    {
        if (extension_loaded('pcntl')) {
            define('AMQP_WITHOUT_SIGNALS', false);

            pcntl_signal(SIGTERM, [$this, 'signalHandler']);
            pcntl_signal(SIGHUP, [$this, 'signalHandler']);
            pcntl_signal(SIGINT, [$this, 'signalHandler']);
            pcntl_signal(SIGQUIT, [$this, 'signalHandler']);
            pcntl_signal(SIGUSR1, [$this, 'signalHandler']);
            pcntl_signal(SIGUSR2, [$this, 'signalHandler']);
            pcntl_signal(SIGALRM, [$this, 'alarmHandler']);
        } else {
            echo 'Unable to process signals.' . PHP_EOL;

            exit(1);
        }
    }

    /**
     * Signal handler
     *
     * @param  int $signalNumber
     * @return void
     */
    public function signalHandler(int $signalNumber): void
    {
        $this->events->dispatch('rabbitmq.worker.signal', [$signalNumber]);

        switch ($signalNumber) {
            case SIGTERM:  // 15 : supervisor default stop
            case SIGQUIT:  // 3  : kill -s QUIT
                $this->stopHard();

                break;
            case SIGINT:   // 2  : ctrl + c
                $this->stop();

                break;
            case SIGHUP:   // 1  : kill -s HUP
                $this->restart();

                break;
            case SIGUSR1:  // 10 : kill -s USR1
                pcntl_alarm(1);

                break;
            case SIGUSR2:  // 12 : kill -s USR2
                pcntl_alarm(10);

                break;
            default:
                break;
        }
    }

    /**
     * Alarm handler
     *
     * @param  int $signalNumber
     * @return void
     */
    public function alarmHandler(int $signalNumber): void
    {
        $this->events->dispatch('rabbitmq.worker.alarm', [$signalNumber, memory_get_usage(true)]);
    }

    /**
     * Restart the consumer on an existing connection
     */
    public function restart(): void
    {
        $this->restart = true;
        $this->stopHard();
    }

    /**
     * Close the connection to the server
     */
    public function stopHard(): void
    {
        $this->events->dispatch('rabbitmq.worker.stopping', ['hard', 0]);
        $this->manager->getConnection()->close();
    }

    /**
     * Close the channel to the server
     */
    public function stopSoft(): void
    {
        $this->events->dispatch('rabbitmq.worker.stopping', ['soft', 0]);
        $this->manager->getConnection()->getChannel()->close();
    }

    /**
     * Tell the server you are going to stop consuming
     * It will finish up the last message and not send you any more
     *
     * @param int $status
     */
    public function stop(int $status = 0): void
    {
        if ($status > 0) {
            $this->events->dispatch('rabbitmq.worker.stopping', ['stop', $status]);

            exit($status);
        }

        $this->events->dispatch('rabbitmq.worker.stopping', ['stop', 0]);

        // this gets stuck and will not exit without the last two parameters set
        $this->channel->basic_cancel($this->consumerTag, false, true);
    }

    public function shouldRestart(): bool
    {
        return $this->restart;
    }
}
