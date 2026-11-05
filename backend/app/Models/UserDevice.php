<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser a person signed in from (a long-lived device cookie). Not tenant-scoped,
 * because Super Admin devices have no hotel: always filter by user_id or hotel_id.
 */
#[Fillable(['user_id', 'hotel_id', 'token_hash', 'name', 'ip', 'status', 'approved_by', 'last_seen_at'])]
class UserDevice extends Model
{
    public const STATUSES = ['active', 'pending', 'signed_out', 'blocked'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
