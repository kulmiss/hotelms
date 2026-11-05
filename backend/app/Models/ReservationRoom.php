<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'reservation_id', 'room_type_id', 'room_id', 'check_in_at', 'check_out_at', 'blocked_until', 'actual_check_in_at', 'actual_check_out_at', 'adults', 'children', 'total_amount', 'status'])]
class ReservationRoom extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'blocked_until' => 'datetime',
            'actual_check_in_at' => 'datetime', 'actual_check_out_at' => 'datetime',
            'total_amount' => 'decimal:2', 'adults' => 'integer', 'children' => 'integer',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    /** @return HasMany<ReservationRoomRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ReservationRoomRate::class)->orderBy('date');
    }

    /** @return BelongsToMany<Guest, $this> */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guests')->withTimestamps();
    }

    public function activityName(): string
    {
        return 'Room of '.($this->reservation?->code ?? '#'.$this->reservation_id);
    }
}
