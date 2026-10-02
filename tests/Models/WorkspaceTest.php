<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Workspace model', function () {
    it('has correct fillable attributes', function () {
        $model = new Workspace();

        expect($model->getFillable())->toBe(['name', 'slug', 'settings']);
    });

    it('persists settings as JSON', function () {
        Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'settings' => ['locale' => 'ru'],
        ]);

        $fresh = Workspace::query()->where('slug', 'acme')->firstOrFail();

        expect($fresh->settings)->toBe(['locale' => 'ru']);
    });

    it('enforces a unique slug', function () {
        Workspace::create(['name' => 'Acme', 'slug' => 'acme']);

        expect(fn (): mixed => Workspace::create(['name' => 'Acme two', 'slug' => 'acme']))
            ->toThrow(QueryException::class);
    });

    it('has members and groups relations', function () {
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

        expect($workspace->members())->toBeInstanceOf(HasMany::class)
            ->and($workspace->members)->toHaveCount(1)
            ->and($workspace->groups())->toBeInstanceOf(HasMany::class)
            ->and($workspace->groups)->toHaveCount(0);
    });
});
