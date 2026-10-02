<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace membership row (ADR-001): one user in one workspace,
 * unique per (workspace_id, user_id).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property WorkspaceMemberStatus $status
 * @property \Carbon\Carbon|null $joined_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class WorkspaceMember extends Model
{
    use HasTimestamps;

    protected $attributes = [
        'role' => 'member',
        'status' => 'active',
    ];

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'role' => WorkspaceRole::class,
        'status' => WorkspaceMemberStatus::class,
        'joined_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            $model->joined_at ??= now();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
