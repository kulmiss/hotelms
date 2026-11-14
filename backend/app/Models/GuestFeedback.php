<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A guest's feedback on one stay. The guest's stars and words never change once sent; the hotel may add a reply note. */
#[Fillable(['hotel_id', 'reservation_id', 'guest_id', 'branch_id', 'room_id', 'checked_in_by', 'checked_out_by', 'token', 'secret', 'overall', 'cleanliness', 'staff', 'comfort', 'value', 'comment', 'public_ok', 'language', 'submitted_at', 'reply', 'replied_by', 'replied_at', 'expires_at'])]
class GuestFeedback extends Model
{
    use BelongsToHotel;

    protected $table = 'guest_feedback';

    public const RATINGS = ['overall', 'cleanliness', 'staff', 'comfort', 'value'];

    protected $hidden = ['token', 'secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', 'public_ok' => 'boolean', 'submitted_at' => 'datetime', 'replied_at' => 'datetime', 'expires_at' => 'datetime',
            'overall' => 'integer', 'cleanliness' => 'integer', 'staff' => 'integer', 'comfort' => 'integer', 'value' => 'integer',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
