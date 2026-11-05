<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'user_id', 'report_key', 'name', 'filters', 'is_pinned', 'shared_with_roles'])]
class SavedView extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['filters' => 'array', 'shared_with_roles' => 'array', 'is_pinned' => 'boolean'];
    }

    public function activityName(): string
    {
        return (string) $this->name;
    }
}
