<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['hotel_id', 'user_id', 'type', 'file_name', 'rows_count', 'created_ids', 'undone_at', 'undone_by'])]
class Import extends Model
{
    use BelongsToHotel, LogsActivity;

    public const UNDO_HOURS = 24;

    protected function casts(): array
    {
        return ['created_ids' => 'array', 'undone_at' => 'datetime', 'rows_count' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canUndo(): bool
    {
        return $this->undone_at === null && $this->created_at->gt(now()->subHours(self::UNDO_HOURS));
    }

    public function activityName(): string
    {
        return 'Import '.($this->file_name ?? $this->type);
    }
}
