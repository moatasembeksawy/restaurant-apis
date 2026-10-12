<?php

use App\Shared\Support\Http\Middleware\AuthorizeTelescope;
use Laravel\Telescope\Watchers;

$recordAll = env('APP_ENV') === 'local';

return [

    /*
    |--------------------------------------------------------------------------
    | Telescope Master Switch
    |--------------------------------------------------------------------------
    */

    'enabled' => env('TELESCOPE_ENABLED', true),

    'domain' => env('TELESCOPE_DOMAIN'),

    'path' => env('TELESCOPE_PATH', 'telescope'),

    'driver' => env('TELESCOPE_DRIVER', 'database'),

    'storage' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
            'chunk' => 1000,
        ],
    ],

    'queue' => [
        'connection' => env('TELESCOPE_QUEUE_CONNECTION'),
        'queue' => env('TELESCOPE_QUEUE'),
        'delay' => env('TELESCOPE_QUEUE_DELAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard basic auth
    |--------------------------------------------------------------------------
    |
    | Outside local, /telescope asks for these credentials. Leave the password
    | empty and the dashboard stays closed.
    |
    */

    'basic_auth' => [
        'username' => env('TELESCOPE_USERNAME'),
        'password' => env('TELESCOPE_PASSWORD'),
    ],

    'prune_hours' => (int) env('TELESCOPE_PRUNE_HOURS', 48),

    'middleware' => [
        'web',
        AuthorizeTelescope::class,
    ],

    'only_paths' => [
        //
    ],

    'ignore_paths' => [
        'livewire*',
        'nova-api*',
        'pulse*',
        '_boost*',
        '.well-known*',
        'docs*',
        'horizon*',
    ],

    'ignore_commands' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Watchers
    |--------------------------------------------------------------------------
    |
    | Local records everything. Every other environment keeps only the
    | watchers needed for failures. Override a single watcher with its env key.
    |
    */

    'watchers' => [
        Watchers\BatchWatcher::class => env('TELESCOPE_BATCH_WATCHER', $recordAll),

        Watchers\CacheWatcher::class => [
            'enabled' => env('TELESCOPE_CACHE_WATCHER', $recordAll),
            'hidden' => [],
            'ignore' => [],
        ],

        Watchers\ClientRequestWatcher::class => [
            'enabled' => env('TELESCOPE_CLIENT_REQUEST_WATCHER', $recordAll),
            'ignore_hosts' => [],
        ],

        Watchers\CommandWatcher::class => [
            'enabled' => env('TELESCOPE_COMMAND_WATCHER', $recordAll),
            'ignore' => [],
        ],

        Watchers\DumpWatcher::class => [
            'enabled' => env('TELESCOPE_DUMP_WATCHER', $recordAll),
            'always' => env('TELESCOPE_DUMP_WATCHER_ALWAYS', false),
        ],

        Watchers\EventWatcher::class => [
            'enabled' => env('TELESCOPE_EVENT_WATCHER', $recordAll),
            'ignore' => [],
        ],

        Watchers\ExceptionWatcher::class => env('TELESCOPE_EXCEPTION_WATCHER', true),

        Watchers\GateWatcher::class => [
            'enabled' => env('TELESCOPE_GATE_WATCHER', $recordAll),
            'ignore_abilities' => [],
            'ignore_packages' => true,
            'ignore_paths' => [],
        ],

        Watchers\JobWatcher::class => env('TELESCOPE_JOB_WATCHER', true),

        Watchers\LogWatcher::class => [
            'enabled' => env('TELESCOPE_LOG_WATCHER', true),
            'level' => env('TELESCOPE_LOG_LEVEL', 'error'),
        ],

        Watchers\MailWatcher::class => env('TELESCOPE_MAIL_WATCHER', $recordAll),

        Watchers\ModelWatcher::class => [
            'enabled' => env('TELESCOPE_MODEL_WATCHER', $recordAll),
            'events' => ['eloquent.*'],
            'hydrations' => true,
        ],

        Watchers\NotificationWatcher::class => env('TELESCOPE_NOTIFICATION_WATCHER', $recordAll),

        Watchers\QueryWatcher::class => [
            'enabled' => env('TELESCOPE_QUERY_WATCHER', $recordAll),
            'ignore_packages' => true,
            'ignore_paths' => [],
            'slow' => 100,
        ],

        Watchers\RedisWatcher::class => env('TELESCOPE_REDIS_WATCHER', $recordAll),

        Watchers\RequestWatcher::class => [
            'enabled' => env('TELESCOPE_REQUEST_WATCHER', true),
            'size_limit' => env('TELESCOPE_RESPONSE_SIZE_LIMIT', 64),
            'ignore_http_methods' => [],
            'ignore_status_codes' => $recordAll ? [] : range(100, 499),
        ],

        Watchers\ScheduleWatcher::class => env('TELESCOPE_SCHEDULE_WATCHER', true),

        Watchers\ViewWatcher::class => env('TELESCOPE_VIEW_WATCHER', $recordAll),
    ],
];
