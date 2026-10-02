<?php

declare(strict_types=1);

describe('TgBMAuditCommand', function () {
    it('fails when custom path does not exist', function () {
        $this->artisan('tgbm:audit', ['--path' => 'nonexistent/path'])
            ->expectsOutputToContain('Path not found:')
            ->assertFailed();
    });

    it('returns success when scanning an empty directory', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-empty-'.uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir])
                ->expectsOutputToContain('No highload-stability anti-patterns found.')
                ->assertOk();
        } finally {
            rmdir($tmpDir);
        }
    });

    it('returns success on clean PHP file', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-clean-'.uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir.'/clean.php', '<?php class Foo { public function bar() {} }');

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir])
                ->expectsOutputToContain('No highload-stability anti-patterns found.')
                ->assertOk();
        } finally {
            @unlink($tmpDir.'/clean.php');
            rmdir($tmpDir);
        }
    });

    it('detects swallowed exception in strict mode', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-strict-'.uniqid();
        mkdir($tmpDir, 0755, true);

        $code = '<?php'."\n"
            .'class Bad {'."\n"
            .'    public function doWork() {'."\n"
            .'        try {'."\n"
            .'            $this->externalCall();'."\n"
            .'        } catch (\\Throwable $e) {'."\n"
            .'            // swallowed'."\n"
            .'        }'."\n"
            .'    }'."\n"
            .'}'."\n";

        file_put_contents($tmpDir.'/bad.php', $code);

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir, '--strict' => true])
                ->assertFailed();
        } finally {
            @unlink($tmpDir.'/bad.php');
            rmdir($tmpDir);
        }
    });

    it('reports findings but succeeds in non-strict mode', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-nonstrict-'.uniqid();
        mkdir($tmpDir, 0755, true);

        $code = '<?php'."\n"
            .'class Bad {'."\n"
            .'    public function doWork() {'."\n"
            .'        try {'."\n"
            .'            $this->externalCall();'."\n"
            .'        } catch (\\Throwable $e) {'."\n"
            .'            // swallowed'."\n"
            .'        }'."\n"
            .'    }'."\n"
            .'}'."\n";

        file_put_contents($tmpDir.'/bad.php', $code);

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir])
                ->assertOk();
        } finally {
            @unlink($tmpDir.'/bad.php');
            rmdir($tmpDir);
        }
    });

    it('detects constructor IO anti-pattern', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-ctor-io-'.uniqid();
        mkdir($tmpDir, 0755, true);

        $code = '<?php'."\n"
            .'class CtorIo {'."\n"
            .'    public function __construct() {'."\n"
            .'        $this->client = new \\Redis();'."\n"
            .'    }'."\n"
            .'}'."\n";

        file_put_contents($tmpDir.'/ctor.php', $code);

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir, '--strict' => true])
                ->assertFailed();
        } finally {
            @unlink($tmpDir.'/ctor.php');
            rmdir($tmpDir);
        }
    });

    it('detects non-atomic counter anti-pattern', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-counter-'.uniqid();
        mkdir($tmpDir, 0755, true);

        $code = '<?php'."\n"
            .'class Counter {'."\n"
            .'    public function bump() {'."\n"
            .'        $this->cache->increment("key");'."\n"
            .'    }'."\n"
            .'}'."\n";

        file_put_contents($tmpDir.'/counter.php', $code);

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir, '--strict' => true])
                ->assertFailed();
        } finally {
            @unlink($tmpDir.'/counter.php');
            rmdir($tmpDir);
        }
    });

    it('ignores incrementWithTtl as valid pattern', function () {
        $tmpDir = sys_get_temp_dir().'/tgbm-audit-incrttl-'.uniqid();
        mkdir($tmpDir, 0755, true);

        $code = '<?php'."\n"
            .'class ValidCounter {'."\n"
            .'    public function bump() {'."\n"
            .'        $this->cache->incrementWithTtl("key", 60);'."\n"
            .'    }'."\n"
            .'}'."\n";

        file_put_contents($tmpDir.'/valid.php', $code);

        try {
            $this->artisan('tgbm:audit', ['--path' => $tmpDir])
                ->expectsOutputToContain('No highload-stability anti-patterns found.')
                ->assertOk();
        } finally {
            @unlink($tmpDir.'/valid.php');
            rmdir($tmpDir);
        }
    });
});
