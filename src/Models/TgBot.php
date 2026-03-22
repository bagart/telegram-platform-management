<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $bot_id
 * @property string $token
 * @property string|null $secret_token
 */
class TgBot extends Model
{
    use HasTimestamps;

    protected $primaryKey = 'bot_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'bot_id',
        'token',
        'secret_token',
    ];

    protected $hidden = [
        'token',
        'secret_token',
    ];

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(TgBotOwner::class, 'tg_bot_owners', 'bot_id', 'user_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(TgBotModule::class, 'tg_bot_modules', 'bot_id', 'chat_id');
    }
}
