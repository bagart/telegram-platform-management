<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('WorkspaceGroup model', function () {
    it('has correct fillable attributes', function () {
        $model = new WorkspaceGroup();

        expect($model->getFillable())->toBe(['workspace_id', 'name', 'slug', 'description']);
    });

    it('enforces a unique slug within a workspace but allows it across workspaces', function () {
        $one = Workspace::create(['name' => 'One', 'slug' => 'one']);
        $two = Workspace::create(['name' => 'Two', 'slug' => 'two']);

        WorkspaceGroup::create([
            'workspace_id' => $one->id,
            'name' => 'Moderators',
            'slug' => 'mods',
        ]);
        WorkspaceGroup::create([
            'workspace_id' => $two->id,
            'name' => 'Moderators',
            'slug' => 'mods',
        ]);

        expect(fn (): mixed => WorkspaceGroup::create([
            'workspace_id' => $one->id,
            'name' => 'Moderators again',
            'slug' => 'mods',
        ]))->toThrow(QueryException::class);
    });

    it('has workspace and members relations', function () {
        $model = new WorkspaceGroup();

        expect($model->workspace())->toBeInstanceOf(BelongsTo::class)
            ->and($model->members())->toBeInstanceOf(HasMany::class);
    });
});
