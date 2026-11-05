<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'created_by', 'name', 'measures', 'group_by', 'filters', 'chart_type', 'shared_with_roles'])]
class CustomReport extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['measures' => 'array', 'filters' => 'array', 'shared_with_roles' => 'array'];
    }

    public function activityName(): string
    {
        return (string) $this->name;
    }
}
