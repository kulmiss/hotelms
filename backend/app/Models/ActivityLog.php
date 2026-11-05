<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who changed what, and when. Hotel entries are tenant-scoped; platform
 * entries (Super Admin actions) have no hotel and are read across hotels.
 */
#[Fillable(['hotel_id', 'user_id', 'event', 'subject_type', 'subject_id', 'description', 'properties', 'ip_address'])]
class ActivityLog extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
