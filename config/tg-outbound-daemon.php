<?php

declare(strict_types=1);

/**
 * Outbound Daemon Configuration.
 *
 * HTTP transport and socket pool settings for the Telegram outbound daemon
 * (OutboundWorker via AsyncKernel). The daemon transport defaults to curl-multi
 * and can be overridden via the TG_OUTBOUND_TRANSPORT env.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Daemon Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the outbound daemon process.
    | Active transport determined by TG_OUTBOUND_TRANSPORT env.
    |
    */
    'daemon' => [
        'memory_limit' => env('TG_OUTBOUND_DAEMON_MEMORY_LIMIT', '256M'),
        'transport' => env('TG_OUTBOUND_TRANSPORT', 'curl-multi'),

        /*
        |--------------------------------------------------------------------------
        | Socket Connection Pool (asc-socket transport only)
        |--------------------------------------------------------------------------
        |
        | When the daemon runs on the asc-socket transport with a pool enabled,
        | completed HTTP/1.1 connections are returned to a keep-alive pool and
        | reused for subsequent requests to the same host — eliminating repeated
        | TCP+TLS handshakes against api.telegram.org.
        |
        | `warm_connections` keeps N ready TLS connections open to `warm_host` so
        | the very first requests pay no handshake cost.
        |
        | Disabled by default. No effect on curl-multi / guzzle transports.
        |
        */
        'socket_pool' => [
            'enabled' => env('TG_OUTBOUND_SOCKET_POOL', false),
            'warm_connections' => (int) env('TG_OUTBOUND_WARM_CONNECTIONS', 4),
            'warm_host' => env('TG_OUTBOUND_WARM_HOST', 'api.telegram.org'),
            'max_idle_per_host' => (int) env('TG_OUTBOUND_MAX_IDLE_PER_HOST', 8),
            'max_idle_total' => (int) env('TG_OUTBOUND_MAX_IDLE_TOTAL', 32),
            'idle_timeout' => (float) env('TG_OUTBOUND_IDLE_TIMEOUT', 60.0),
        ],
    ],
];
