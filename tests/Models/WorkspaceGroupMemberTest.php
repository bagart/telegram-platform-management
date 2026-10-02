<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceGroup;
use BAGArt\TelegramBotManagement\Models\WorkspaceGroupMember;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('WorkspaceGroupMember model', function () {
    it('has correct fillable attributes', function () {
        $model = new WorkspaceGroupMember();

        expect($model->getFillable())->toBe(['group_id', 'user_id']);
    });

    it('has group relation', function () {
        $model = new WorkspaceGroupMember();

        expect($model->group())->toBeInstanceOf(BelongsTo::class);
    });

    it('enforces one row per user per group', function () {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $group = WorkspaceGroup::create([
            'workspace_id' => $workspace->id,
            'name' => 'Moderators',
            'slug' => 'mods',
        ]);
        $user = User::create([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ]);
        WorkspaceGroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);

        expect(fn (): mixed => WorkspaceGroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]))->toThrow(QueryException::class);
    });
});
