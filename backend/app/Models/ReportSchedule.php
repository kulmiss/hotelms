<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'created_by', 'name', 'report_key', 'custom_report_id', 'branch_id', 'frequency', 'day', 'time', 'channels', 'recipients', 'extra_emails', 'is_active', 'next_run_at', 'last_sent_at', 'last_error'])]
class ReportSchedule extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['channels' => 'array', 'recipients' => 'array', 'extra_emails' => 'array', 'is_active' => 'boolean', 'next_run_at' => 'datetime', 'last_sent_at' => 'datetime', 'day' => 'integer'];
    }

    public function activityName(): string
    {
        return (string) $this->name;
    }
}
