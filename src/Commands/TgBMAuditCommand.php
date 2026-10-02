<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;

/**
 * tgbm:audit — grep-based highload-stability checker.
 *
 * Scans the BAGArt libraries' src/ directories for anti-patterns that violate
 * the rules in the highload-stability skill. Advisory (exit 0) unless --strict
 * is passed (then findings cause non-zero exit, for CI gating).
 *
 * What it checks (each maps to a checklist.md rule):
 *   1. swallowed-exceptions  — catch (Throwable|Exception) without rethrow in the block
 *   2. constructor-io        — network I/O calls inside __construct() bodies
 *   3. non-atomic-counter    — bare ->increment( usage outside OutboundCacheContract impls
 *
 * False positives are expected (heuristic, not AST) — review each finding.
 */
#[AsCommand('tgbm:audit', 'Scan BAGArt libs for highload-stability anti-patterns')]
class TgBMAuditCommand extends Command
{
    protected $signature = 'tgbm:audit
                            {--strict : Exit non-zero if any findings (for CI)}
                            {--all : Scan all BAGArt libs (default: scan only the outbound hot path)}
                            {--path= : Custom root path to scan (overrides --all)}';

    protected $description = 'Scan BAGArt libs for highload-stability anti-patterns';

    /** @var array<int, array{rule:string,file:string,line:int,snippet:string}> */
    private array $findings = [];

    public function handle(): int
    {
        // Default scope: the outbound hot path (where the swallowed-exception rule
        // actually applies per checklist #11). --all broadens to every BAGArt lib,
        // which produces more noise from async Promise/Future routing catches.
        $defaultPath = 'misc/BAGArt/telegram-bot-lib/src/Outbound';

        if ($this->option('path')) {
            $optionPath = (string) $this->option('path');
            $base = str_starts_with($optionPath, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $optionPath) === 1
                ? $optionPath
                : base_path($optionPath);
        } elseif ($this->option('all')) {
            $base = base_path('misc/BAGArt');
        } else {
            $base = base_path($defaultPath);
        }

        if (!is_dir($base)) {
            $this->error("Path not found: {$base}");

            return self::FAILURE;
        }

        $files = (new Finder())
            ->files()
            ->name('*.php')
            ->exclude('vendor')
            ->notPath('/(^|\/)vendor\//')
            ->in($base);

        foreach ($files as $file) {
            $this->scanFile($file->getRealPath());
        }

        $this->report();

        if ($this->findings === []) {
            $this->info('✓ No highload-stability anti-patterns found.');

            return self::SUCCESS;
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function scanFile(string $path): void
    {
        $source = file_get_contents($path);
        if ($source === false) {
            return;
        }

        $lines = explode("\n", $source);
        $this->checkSwallowedExceptions($path, $lines);
        $this->checkConstructorIo($path, $source, $lines);
        $this->checkNonAtomicCounters($path, $lines);
    }

    /**
     * Rule #11 — catch (Throwable|Exception) that does not rethrow in the same block.
     * Heuristic: a catch clause whose body has no `throw` and no legitimate
     * async-error-propagation token (reject/setException/log/etc.). False positives
     * are still possible — review each finding.
     */
    private function checkSwallowedExceptions(string $path, array $lines): void
    {
        // Tokens that indicate the exception is being propagated/handled, not swallowed.
        // Matches both fluent (->reject($e)) and stored ($this->error = $e) patterns.
        $legitPatterns = [
            '\bthrow\b',
            '->reject',
            '->setException',
            '->setFailure',
            '->onError',
            '->error',
            '->warning',
            '->critical',
            '->log',
            '->emergency',
            '->alert',
            '->notify',
            '->report',
            '->dispatch',
            '->record',          // ->recordFailed, ->recordSent, etc. (stats propagation)
            '\$this->.*=\s*\$',
            'captureException',
            'rescue',
        ];
        $legitRegex = '/('.implode('|', $legitPatterns).')/';

        $total = count($lines);
        for ($i = 0; $i < $total; $i++) {
            if (!preg_match('/\bcatch\s*\(\s*\\\\?(Throwable|Exception)\b/', $lines[$i])) {
                continue;
            }

            $depth = 0;
            $hasEnteredBody = false;
            $hasLegitHandling = false;
            for ($j = $i; $j < $total; $j++) {
                $line = $lines[$j];
                // On the catch line itself, isolate the body-opening brace
                // (the line may also close the preceding try block, e.g. `} catch (...) {`).
                if ($j === $i) {
                    // The catch line opens the body with `{`. Everything after that
                    // brace is the first line of the body. The opening brace itself
                    // counts as depth 1.
                    $bracePos = strpos($line, '{');
                    if ($bracePos === false) {
                        continue; // multi-line catch signature; body starts later
                    }
                    $hasEnteredBody = true;
                    $after = substr($line, $bracePos + 1);
                    if (preg_match($legitRegex, $after)) {
                        $hasLegitHandling = true;
                    }
                    // Start at 1 (the opening brace) and subtract any close braces
                    // that appear on the same line (one-liner body case).
                    $depth = 1 + substr_count($after, '{') - substr_count($after, '}');
                    if ($depth <= 0) {
                        break; // body opened and closed on the same line
                    }
                    continue;
                }

                $depth += substr_count($line, '{') - substr_count($line, '}');
                if (preg_match($legitRegex, $line)) {
                    $hasLegitHandling = true;
                }
                if ($hasEnteredBody && $depth <= 0) {
                    break;
                }
            }

            if (!$hasLegitHandling) {
                $this->findings[] = [
                    'rule' => 'swallowed-exceptions',
                    'file' => $path,
                    'line' => $i + 1,
                    'snippet' => trim($lines[$i]),
                ];
            }
        }
    }

    /**
     * Rule #1 — constructors must not perform network I/O (lazy-connection rule).
     * Heuristic: any of the I/O tokens appearing between the __construct() body braces.
     */
    private function checkConstructorIo(string $path, string $source, array $lines): void
    {
        $ioTokens = [
            'new \Redis',
            'new Redis(',
            'new \RedisCluster',
            'new RedisCluster(',
            'stream_socket_client',
            'fsockopen',
            'pfsockopen',
            'socket_create',
            'new Client(',
            'new GuzzleHttp',
            'curl_init',
        ];

        // Find __construct( ... ) body span.
        if (!preg_match('/function\s+__construct\s*\(/', $source, $ctorMatch, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $ctorLine = substr_count(substr($source, 0, $ctorMatch[0][1]), "\n");

        // Walk to the opening brace, then track depth until it closes.
        $total = count($lines);
        $depth = 0;
        $inBody = false;
        for ($i = $ctorLine; $i < $total; $i++) {
            if (str_contains($lines[$i], '{')) {
                $inBody = true;
            }
            if ($inBody) {
                foreach ($ioTokens as $tok) {
                    if (str_contains($lines[$i], $tok)) {
                        $this->findings[] = [
                            'rule' => 'constructor-io',
                            'file' => $path,
                            'line' => $i + 1,
                            'snippet' => trim($lines[$i]),
                        ];
                    }
                }
                $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
                if ($depth <= 0) {
                    return; // constructor body ended
                }
            }
        }
    }

    /**
     * Rule #2 — counter increments under concurrency must use incrementWithTtl,
     * not the bare get-check-set increment(). Heuristic: ->increment( calls in
     * classes that do NOT implement OutboundCacheContract.
     */
    private function checkNonAtomicCounters(string $path, array $lines): void
    {
        $implementsOutboundCache = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'OutboundCacheContract')) {
                $implementsOutboundCache = true;

                break;
            }
        }
        if ($implementsOutboundCache) {
            return; // this class is allowed to use increment() in its impl
        }

        foreach ($lines as $i => $line) {
            // Bare ->increment( or ->inc( on a cache/store, but NOT incrementWithTtl.
            if (preg_match('/->(increment|inc)\s*\(/', $line)
                && !str_contains($line, 'incrementWithTtl')) {
                $this->findings[] = [
                    'rule' => 'non-atomic-counter',
                    'file' => $path,
                    'line' => $i + 1,
                    'snippet' => trim($line),
                ];
            }
        }
    }

    private function report(): void
    {
        if ($this->findings === []) {
            return;
        }

        $byRule = [];
        foreach ($this->findings as $f) {
            $byRule[$f['rule']][] = $f;
        }

        $this->warn(sprintf('Found %d potential highload-stability anti-pattern(s):', count($this->findings)));
        $this->newLine();

        foreach ($byRule as $rule => $items) {
            $this->line("<options=bold>● {$rule}</> (".count($items).')');
            foreach ($items as $f) {
                $rel = str_replace(base_path().'/', '', $f['file']);
                $this->line(sprintf('  <fg=gray>%s:%d</>  %s', $rel, $f['line'], $f['snippet']));
            }
            $this->newLine();
        }

        $this->comment('Heuristic checks — review each finding. Some may be false positives.');
        $this->comment('See docs/skills/highload-stability/rules/checklist.md for the full rules.');
    }
}
