<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('WorkspaceMember model', function () {
    it('has correct fillable attributes', function () {
        $model = new WorkspaceMember();

        expect($model->getFillable())->toBe([
            'workspace_id',
            'user_id',
            'role',
            'status',
            'joined_at',
        ]);
    });

    it('defaults to active member with joined_at set', function () {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ]);

        $member = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        expect($member->role)->toBe(WorkspaceRole::Member)
            ->and($member->status)->toBe(WorkspaceMemberStatus::Active)
            ->and($member->joined_at)->not->toBeNull();
    });

    it('has workspace relation', function () {
        $model = new WorkspaceMember();

        expect($model->workspace())->toBeInstanceOf(BelongsTo::class);
    });

    it('enforces one membership per user per workspace', function () {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ]);
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        expect(fn (): mixed => WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]))->toThrow(QueryException::class);
    });
});
