<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'branch_id', 'room_id', 'reservation_id', 'assigned_to', 'type', 'priority', 'status', 'due_at', 'started_at', 'completed_at', 'completed_by', 'notes', 'created_by'])]
class HousekeepingTask extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public const TYPES = ['cleaning', 'inspection', 'maintenance'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activityName(): string
    {
        return ucfirst($this->type).' task, room '.($this->room?->number ?? $this->room_id);
    }
}
