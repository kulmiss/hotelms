<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['hotel_id', 'branch_id', 'room_type_id', 'number', 'floor', 'status', 'notes'])]
class Room extends Model
{
    use BelongsToHotel, LogsActivity, SoftDeletes;

    public const STATUSES = ['available', 'occupied', 'dirty', 'cleaning', 'maintenance', 'out_of_order'];

    /** The secret of the calendar export link never goes out with the room (Settings → Calendar sync shows the link). */
    protected $hidden = ['ical_token', 'ical_secret'];

    protected function casts(): array
    {
        return ['ical_secret' => 'encrypted'];
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<HousekeepingTask, $this> */
    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    public function activityName(): string
    {
        return 'Room '.$this->number;
    }
}
