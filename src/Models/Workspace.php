<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workspace = tenant = admin group (ADR-001). A platform user may belong
 * to many workspaces; slug is the stable public identifier.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Workspace extends Model
{
    use HasTimestamps;

    protected $fillable = [
        'name',
        'slug',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(WorkspaceGroup::class);
    }
}
