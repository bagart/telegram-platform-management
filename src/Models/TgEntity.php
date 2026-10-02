<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace-owned Telegram entity (D2, ADR-003): a channel, chat group or
 * bot linked into one workspace. Rows are created by EntityLinkService and
 * outlive the bot-scoped tg_chats projection.
 *
 * Target shape per kind (DB-enforced where the driver allows it):
 *  - channel / chat_group → external_id set, bot_id NULL;
 *  - bot → bot_id set, external_id NULL.
 *
 * @property int $id
 * @property int $workspace_id
 * @property EntityKind $kind
 * @property int|null $external_id
 * @property string|null $bot_id
 * @property string|null $title
 * @property string|null $username
 * @property int|null $linked_by
 * @property \Carbon\Carbon|null $linked_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class TgEntity extends Model
{
    use HasFactory;
    use HasTimestamps;

    protected $fillable = [
        'workspace_id',
        'kind',
        'external_id',
        'bot_id',
        'title',
        'username',
        'linked_by',
        'linked_at',
    ];

    protected $casts = [
        'kind' => EntityKind::class,
        'external_id' => 'integer',
        'linked_by' => 'integer',
        'linked_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TgBot::class, 'bot_id', 'bot_id');
    }

    /** Factory lives in the root app (Database\Factories), not inside the lib. */
    public static function newFactory(): Factory
    {
        return \Database\Factories\TgEntityFactory::new();
    }
}
