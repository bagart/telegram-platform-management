<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditException;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\DefaultAuditFailurePolicyResolver;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Services\WorkspaceAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Phase 6 gap scan (ADR-003 "permission / membership changes emit audit
 * entries via AuditSinkContract"): WorkspaceAuditRecorder must honour the
 * audit failure policy — FAIL_CLOSED rethrows so the caller rolls back,
 * FAIL_OPEN logs and lets the mutation stand, and a host without the audit
 * module falls back to log-only instead of crashing.
 */

/**
 * Sink that fails on every append (infrastructure failure simulation).
 */
final class GapExplodingAuditSink implements AuditSinkContract
{
    public function append(AuditEntry $entry): void
    {
        throw new \RuntimeException('audit storage unavailable');
    }
}

function gapAuditOwner(string $name): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => $name,
        'email' => null,
        'password' => null,
        'telegram_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceAuditRecorder failure policy', function () {
    it('rethrows a failing append as AuditException under FAIL_CLOSED', function () {
        app()->instance(AuditSinkContract::class, new GapExplodingAuditSink());
        app()->instance(
            AuditFailurePolicyResolver::class,
            new DefaultAuditFailurePolicyResolver(defaultPolicy: AuditFailurePolicy::FailClosed->value),
        );

        $owner = gapAuditOwner('Fail Closed Owner');
        $service = app(WorkspaceAdminService::class);

        expect(fn (): mixed => $service->createWorkspace($owner, 'Fail Closed Co'))
            ->toThrow(AuditException::class, "Audit append failed for operation 'workspace.created'");
    });

    it('logs and lets the mutation stand under FAIL_OPEN', function () {
        Log::spy();
        app()->instance(AuditSinkContract::class, new GapExplodingAuditSink());
        app()->instance(
            AuditFailurePolicyResolver::class,
            new DefaultAuditFailurePolicyResolver(defaultPolicy: AuditFailurePolicy::FailOpen->value),
        );

        $owner = gapAuditOwner('Fail Open Owner');
        $workspace = app(WorkspaceAdminService::class)->createWorkspace($owner, 'Fail Open Co');

        expect(Workspace::query()->where('slug', 'fail-open-co')->exists())->toBeTrue()
            ->and(DB::table('workspace_members')->where('workspace_id', $workspace->id)->count())->toBe(1);

        Log::shouldHaveReceived(
            'warning',
            fn (string $message, array $context): bool => $message === 'workspace audit append failed (fail_open)',
        );
    });

    it('drops membership entries log-only when the host has no audit sink bound', function () {
        Log::spy();
        $app = app();
        unset($app[AuditSinkContract::class]);

        expect(app()->bound(AuditSinkContract::class))->toBeFalse();

        $owner = gapAuditOwner('No Sink Owner');
        $workspace = app(WorkspaceAdminService::class)->createWorkspace($owner, 'No Sink Co');

        expect(Workspace::query()->where('slug', 'no-sink-co')->exists())->toBeTrue()
            ->and(DB::table('workspace_members')->where('workspace_id', $workspace->id)->count())->toBe(1);

        Log::shouldHaveReceived(
            'info',
            fn (string $message, array $context): bool => $message === 'workspace.membership',
        );
    });
});
