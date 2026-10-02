<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row of the tg_bots ↔ owners table (unique per bot_id + user_id,
 * see the create_tg_bot_owners_table migration).
 *
 * D7/D11 (ADR-001/ADR-003): user_id is the canonical platform users.id —
 * the sole interpretation. This table is a legacy ownership FACT only:
 * it is NOT a rights source, no production code writes it, and its rows are
 * disposable (no backfill). Rights and web visibility resolve over
 * workspaces through Services\BotOwnershipResolver. The model and relation
 * are kept because other packages still read them.
 */
class TgBotOwner extends Model
{
    use HasTimestamps;

    protected $fillable = [
        'bot_id',
        'user_id',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TgBot::class, 'bot_id', 'bot_id');
    }
}
