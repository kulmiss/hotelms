<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'type', 'threshold', 'channels', 'recipients', 'is_active', 'last_checked_at'])]
class AlertRule extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['threshold' => 'array', 'channels' => 'array', 'recipients' => 'array', 'is_active' => 'boolean', 'last_checked_at' => 'datetime'];
    }

    public function activityName(): string
    {
        return (string) $this->type;
    }
}
