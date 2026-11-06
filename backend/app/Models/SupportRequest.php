<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A question from a hotel to HotelMS (app, Telegram or a logged phone call). */
#[Fillable(['hotel_id', 'user_id', 'channel', 'subject', 'priority', 'status', 'assigned_to', 'last_message_at'])]
class SupportRequest extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open', 'waiting', 'solved'];

    public const CHANNELS = ['app', 'telegram', 'phone'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
