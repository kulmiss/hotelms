<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Super Admin "log in as hotel" session (platform-level, not tenant-scoped). */
#[Fillable(['super_admin_id', 'hotel_id', 'impersonated_user_id', 'reason', 'started_at', 'ended_at', 'ip_address'])]
class ImpersonationLog extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function impersonated(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
