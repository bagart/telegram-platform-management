<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClient;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Outbound\Config\OutboundSetup;
use BAGArt\TelegramBot\TgBotSetupFactory;
use Illuminate\Console\Command;
use Redis;

class TgOutboundDaemonCommand extends Command
{
    protected $signature = 'tgbm:outbound-daemon
                            {--mode=single : Daemon mode (single|multi)}
                            {--redis-host=127.0.0.1 : Redis host}
                            {--redis-port=6379 : Redis port}
                            {--redis-timeout=2.0 : Redis connection timeout}
                            {--memory-limit=256M : PHP memory limit for daemon}
                            {--warm-connections= : Override config warm_connections}
                            {--debug : Enable debug logging}';

    protected $description = 'Start the Telegram outbound worker daemon';

    public function handle(
        ASKLogWrapper $logger,
    ): int {
        $this->configureEnvironment();

        $this->line('Starting OutboundWorker daemon...');

        $this->warmSocketPool($logger);

        $mode = (string)$this->option('mode');
        $outboundSetup = $this->resolveOutboundSetup($mode);

        new AsyncKernel(logger: $logger)
            ->addDaemon($outboundSetup->daemon)
            ->run();

        return self::SUCCESS;
    }

    /**
     * In multi-mode, build a fresh OutboundSetup with Redis queue (the singleton from
     * ServiceProvider is always in-memory — not suitable for multiple workers).
     * In single-mode, use the existing singleton.
     */
    protected function resolveOutboundSetup(string $mode): OutboundSetup
    {
        if ($mode !== 'multi') {
            return app(OutboundSetup::class);
        }

        $redis = new Redis();
        $redisHost = (string)$this->option('redis-host');
        $redis->connect(
            host: $redisHost !== '' ? $redisHost : (string) config('database.redis.default.host', '127.0.0.1'),
            port: (int)$this->option('redis-port') ?: (int) config('database.redis.default.port', 6379),
            timeout: (float)$this->option('redis-timeout') ?: 2.0,
        );

        /** @var TgBotSetupFactory $factory */
        $factory = app(TgBotSetupFactory::class);

        return $factory->createOutbound(
            redis: $redis,
            tokenResolver: app(\BAGArt\TelegramBot\Contracts\Outbound\BotTokenResolverContract::class),
        );
    }

    private function configureEnvironment(): void
    {
        $memoryLimit = (string)$this->option('memory-limit');

        ini_set('memory_limit', $memoryLimit);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        if ($this->option('debug')) {
            $this->line('Debug mode enabled');
        }
    }

    private function warmSocketPool(ASKLogWrapper $logger): void
    {
        $pool = (array) config('tg-outbound-daemon.daemon.socket_pool', []);

        if (!($pool['enabled'] ?? false) && !$this->option('warm-connections')) {
            return;
        }

        $client = app(HttpsSocketClient::class);

        $warmCount = $this->option('warm-connections') !== null
            ? (int) $this->option('warm-connections')
            : (int) ($pool['warm_connections'] ?? 4);

        if ($warmCount <= 0) {
            return;
        }

        $host = (string) ($pool['warm_host'] ?? 'api.telegram.org');

        $this->line("Warming {$warmCount} socket connections to {$host}...");

        $warmed = TgBotSetupFactory::warmTransport($client, $warmCount, $host, $logger);

        $this->info("Warmed {$warmed} connection(s); idle pool size: {$client->idlePoolSize()}.");
    }
}
