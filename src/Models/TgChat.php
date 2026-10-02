<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform chat registry row (RFC §9.1): one chat known to one bot.
 *
 * The real PK is composite (bot_id, chat_id); Eloquent cannot represent it,
 * so this model is read/update-oriented — writes go through multi-row
 * upserts keyed by both columns.
 *
 * @property string $bot_id
 * @property int $chat_id
 * @property string $type
 * @property string|null $title
 * @property string|null $username
 * @property bool $is_active
 * @property string|null $deactivate_reason
 * @property int|null $member_count
 * @property \Carbon\Carbon|null $last_seen_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class TgChat extends Model
{
    use HasTimestamps;

    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'bot_id',
        'chat_id',
        'type',
        'title',
        'username',
        'is_active',
        'deactivate_reason',
        'member_count',
        'last_seen_at',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TgBot::class, 'bot_id', 'bot_id');
    }
}
