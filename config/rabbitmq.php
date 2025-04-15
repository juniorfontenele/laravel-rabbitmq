<?php

declare(strict_types = 1);

use Illuminate\Support\Str;

$hostname = gethostname() ?: 'unknown';
$appName = Str::snake(config('app.name'));
$consumerTag = 'consumer.' . $appName . '.' . $hostname;

return [
    'consumer_tag' => env('RABBITMQ_CONSUMER_TAG', $consumerTag),

    'message_signing' => [
        'enabled' => env('RABBITMQ_SIGNING_ENABLED', false),
        'verification_time_window' => env('RABBITMQ_SIGNING_TIME_WINDOW', 120), // in seconds
        'keys' => [
            'public' => env('RABBITMQ_SIGNING_PUBLIC_KEY'),
            'private' => env('RABBITMQ_SIGNING_PRIVATE_KEY'),
        ],
    ],

    'connections' => [
        'default' => [
            'host' => env('RABBITMQ_HOST', 'localhost'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            'ssl' => [
                'enabled' => env('RABBITMQ_SSL', false),
                'cafile' => env('RABBITMQ_SSL_CAFILE', null),
                'local_cert' => env('RABBITMQ_SSL_CERTFILE', null),
                'local_key' => env('RABBITMQ_SSL_KEYFILE', null),
                'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
            ],
        ],
    ],

    'exchanges' => [
        'global_commands' => [
            'connection' => 'default',
            'name' => 'global.commands',
            'type' => 'fanout', // direct, topic, fanout, headers
            'passive' => false,
            'durable' => true,
            'auto_delete' => false,
            'internal' => false,
            'arguments' => [],
        ],

        'app_commands' => [
            'connection' => 'default',
            'name' => $appName . '.commands',
            'type' => 'fanout', // direct, topic, fanout, headers
            'passive' => false,
            'durable' => true,
            'auto_delete' => false,
            'internal' => false,
            'arguments' => [],
        ],

        'events' => [
            'connection' => 'default',
            'name' => 'global.events',
            'type' => 'topic', // direct, topic, fanout, headers
            'passive' => false,
            'durable' => true,
            'auto_delete' => false,
            'internal' => false,
            'arguments' => [],
        ],
    ],

    'queues' => [
        'global_commands' => [
            'name' => $appName . '.commands',
            'exchange' => 'global_commands',
            'routing_key' => '',
            'passive' => false,
            'durable' => true,
            'exclusive' => false,
            'auto_delete' => false,
            'arguments' => [],
            'prefetch' => [
                'count' => 1,
                'size' => 0,
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay' => 60000, // in milliseconds
            ],
        ],

        'app_commands' => [
            'name' => $appName . '.commands',
            'exchange' => 'app_commands',
            'routing_key' => $appName . '.commands',
            'passive' => false,
            'durable' => true,
            'exclusive' => false,
            'auto_delete' => false,
            'arguments' => [],
            'prefetch' => [
                'count' => 1,
                'size' => 0,
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay' => 60000, // in milliseconds
            ],
        ],
    ],

    'worker' => [
        'memory_limit' => 128, // MB
        'timeout' => 60, // seconds
        'sleep' => 3, // seconds to sleep when no messages
        'max_jobs' => 0, // 0 = unlimited
        'tries' => 1, // default number of attempts
    ],
];
