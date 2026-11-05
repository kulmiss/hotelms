<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'alert_rule_id', 'fingerprint', 'title', 'payload', 'link', 'fired_at', 'acknowledged_by', 'acknowledged_at'])]
class AlertEvent extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['payload' => 'array', 'fired_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function activityName(): string
    {
        return (string) $this->title;
    }
}
