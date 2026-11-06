<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A message in the bell (in-app notifications are always on – automation.md). Per user, so not hotel-scoped. */
#[Fillable(['hotel_id', 'user_id', 'source', 'title', 'body', 'link', 'urgent', 'read_at', 'created_at'])]
class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'read_at' => 'datetime'];
    }
}
