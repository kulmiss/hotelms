<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'branch_id', 'month', 'revenue_target', 'occupancy_target'])]
class ReportTarget extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['revenue_target' => 'decimal:2', 'occupancy_target' => 'decimal:2'];
    }

    public function activityName(): string
    {
        return 'Target '.$this->month;
    }
}
