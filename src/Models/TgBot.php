<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use BAGArt\TelegramBotManagement\Enums\BotAvailability;
use BAGArt\TelegramBotManagement\Enums\BotClass;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $bot_id
 * @property string $token
 * @property string|null $secret_token
 * @property BotClass $class
 * @property BotAvailability $availability
 * @property string|null $external_token decrypted on access; never serialized
 */
class TgBot extends Model
{
    use HasFactory;
    use HasTimestamps;

    protected $primaryKey = 'bot_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'bot_id',
        'token',
        'secret_token',
        'class',
        'availability',
        'external_token',
    ];

    protected $hidden = [
        'token',
        'secret_token',
        'external_token',
    ];

    protected $casts = [
        'class' => BotClass::class,
        'availability' => BotAvailability::class,
        'external_token' => 'encrypted',
    ];

    /**
     * Legacy ownership rows (tg_bot_owners).
     *
     * D7/D11: ownership FACT only — never a rights source, no new writers.
     * Rights resolve over workspaces via Services\BotOwnershipResolver;
     * the relation is kept because other packages still read it.
     */
    public function owners(): HasMany
    {
        return $this->hasMany(TgBotOwner::class, 'bot_id', 'bot_id');
    }

    public function moduleEnablements(): HasMany
    {
        return $this->hasMany(TgModuleEnablement::class, 'bot_id', 'bot_id');
    }

    /** Factory lives in the root app (Database\Factories), not inside the lib. */
    public static function newFactory(): Factory
    {
        return \Database\Factories\TgBotFactory::new();
    }
}
