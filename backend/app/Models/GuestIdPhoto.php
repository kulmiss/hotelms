<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Photo of a guest's ID or passport. The file on disk is encrypted with the app key. */
#[Fillable(['hotel_id', 'guest_id', 'path', 'mime', 'size', 'uploaded_by'])]
class GuestIdPhoto extends Model
{
    use BelongsToHotel;

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
