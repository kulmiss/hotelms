<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something the watchdog found (platform-wide, Super Admin only; not scoped to a hotel). */
#[Fillable(['fingerprint', 'check', 'severity', 'hotel_id', 'title', 'details', 'advice', 'link', 'first_seen_at', 'last_seen_at', 'times', 'resolved_at', 'resolved_by', 'muted_until', 'notified_at'])]
class WatchdogFinding extends Model
{
    protected function casts(): array
    {
        return ['details' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'muted_until' => 'datetime', 'notified_at' => 'datetime'];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
