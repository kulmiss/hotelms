<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A guest message the hotel changed (Settings → Guest messages); without a row the default text is used. */
#[Fillable(['hotel_id', 'key', 'language', 'subject', 'body'])]
class MessageTemplate extends Model
{
    use BelongsToHotel, LogsActivity;

    public function activityName(): string
    {
        return "{$this->key} ({$this->language})";
    }
}
