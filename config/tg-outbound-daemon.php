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
        | Socket Connection Pool (ask-socket transport only)
        |--------------------------------------------------------------------------
        |
        | When the daemon runs on the ask-socket transport with a pool enabled,
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
            'warm_connections' => (int)env('TG_OUTBOUND_WARM_CONNECTIONS', 4),
            'warm_host' => env('TG_OUTBOUND_WARM_HOST', 'api.telegram.org'),
            'warm_interval' => (float)env('TG_OUTBOUND_WARM_INTERVAL', 30.0),
            'max_idle_per_host' => (int)env('TG_OUTBOUND_MAX_IDLE_PER_HOST', 8),
            'max_idle_total' => (int)env('TG_OUTBOUND_MAX_IDLE_TOTAL', 32),
            'idle_timeout' => (float)env('TG_OUTBOUND_IDLE_TIMEOUT', 60.0),
        ],

        /*
        |--------------------------------------------------------------------------
        | DNS Resolver
        |--------------------------------------------------------------------------
        |
        | Deep DNS configuration, applied across all transports.
        |
        | NOTE: This config takes precedence over ASK_DNS_* env vars.
        | When this config is loaded (via Laravel), AskDnsConfigFactory::fromLaravelConfig()
        | is used. The ASK_DNS_* env fallback in AskDnsConfigFactory::fromEnv() only applies
        | in standalone CLI mode (outside Laravel). See AskDnsConfigFactory.php for details.
        |
        | `adapter` selects a resolver engine registered in AskDnsRegistry:
        |   - 'ask-dns'    hand-rolled async UDP/TCP resolver (default engine)
        |   - 'react-dns'  react/dns-based resolver (requires react/dns)
        |   - 'amphp-dns'  amphp/dns-based resolver (requires amphp/dns)
        |   - 'native'     blocking gethostbyname() fallback
        |   - 'App\Dns\MyAdapter' — custom FQCN (auto-registered, must implement
        |     BAGArt\ASKClient\Contracts\Dns\AskDnsAdapterContract)
        |
        | null (default) — per-transport default, decided by transport wiring
        |   BEFORE the registry:
        |   ask-socket  -> ask-dns engine (AskDnsRegistry::DEFAULT_TYPE)
        |   curl/guzzle -> libcurl's own resolver (no PHP-level adapter)
        |
        | Any non-null adapter applies to ALL transports: socket transport uses
        | it directly; curl-multi/guzzle additionally receive CURLOPT_DNS_SERVERS
        | (only effective when libcurl is built with the c-ares async resolver;
        | otherwise the server list is ignored with a warning).
        |
        | `servers` — null falls back to the system resolver (/etc/resolv.conf
        | for ask-dns, the OS for libcurl); a CSV like "8.8.8.8,1.1.1.1" pins
        | specific upstream servers.
        |
        */
        'dns' => [
            'adapter' => env('TG_DNS_ADAPTER', null),
            'servers' => env('TG_DNS_SERVERS', null),
            'timeout' => (float)env('TG_DNS_TIMEOUT', 3.0),
            'ttl' => (float)env('TG_DNS_TTL', 300.0),
            'failure_ttl' => (float)env('TG_DNS_FAILURE_TTL', 10.0),
            'use_tls' => env('TG_DNS_USE_TLS', false),
            'force_ipv4' => env('TG_DNS_FORCE_IPV4', true),
            'warm_up_hosts' => env('TG_DNS_WARMUP', null),
        ],
    ],
];
