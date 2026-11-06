<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A HotelMS version with its "What's new" note (platform table). */
#[Fillable(['version', 'title', 'body', 'status', 'published_at', 'created_by'])]
class Release extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
