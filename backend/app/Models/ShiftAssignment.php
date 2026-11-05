<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'branch_id', 'user_id', 'shift_id', 'date', 'status'])]
class ShiftAssignment extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityName(): string
    {
        return ($this->shift?->name ?? 'Shift').' on '.$this->date?->toDateString();
    }
}
