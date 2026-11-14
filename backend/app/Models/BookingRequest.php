<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A guest's request from the hotel's public booking page; reception books it or declines it. */
#[Fillable(['hotel_id', 'branch_id', 'room_type_id', 'check_in', 'check_out', 'adults', 'children', 'name', 'phone', 'email', 'note', 'status', 'reservation_id', 'handled_by', 'handled_at'])]
class BookingRequest extends Model
{
    use BelongsToHotel;

    protected function casts(): array
    {
        return ['check_in' => 'date', 'check_out' => 'date', 'handled_at' => 'datetime'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id, 'status' => $this->status, 'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email, 'note' => $this->note,
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            'room_type' => $this->roomType ? ['id' => $this->roomType->id, 'name' => $this->roomType->name] : null,
            'check_in' => $this->check_in?->toDateString(), 'check_out' => $this->check_out?->toDateString(),
            'adults' => $this->adults, 'children' => $this->children, 'reservation_id' => $this->reservation_id,
            'created_at' => $this->created_at?->toIso8601String(), 'handled_at' => $this->handled_at?->toIso8601String(),
        ];
    }
}
