<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'branch_id', 'user_id', 'shift_assignment_id', 'date', 'clock_in_at', 'clock_out_at', 'method', 'recorded_by', 'status', 'notes'])]
class Attendance extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityName(): string
    {
        return 'Attendance of '.($this->user?->name ?? '#'.$this->user_id).' on '.$this->date?->toDateString();
    }
}
