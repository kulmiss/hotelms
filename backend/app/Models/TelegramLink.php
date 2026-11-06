<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's Telegram chat for reports and alerts (linked with a one-time code sent to the bot). */
#[Fillable(['hotel_id', 'user_id', 'code', 'code_expires_at', 'chat_id', 'chat_name', 'linked_at'])]
class TelegramLink extends Model
{
    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'linked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
