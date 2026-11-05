<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'key', 'last'])]
class NumberSequence extends Model
{
    use BelongsToHotel;

    /** Next number for a key, safe when two people save at the same moment (call inside a transaction). */
    public static function next(string $key): int
    {
        static::createOrFirst(['key' => $key], ['last' => 0]);
        $row = static::where('key', $key)->lockForUpdate()->firstOrFail();
        $row->increment('last');

        return $row->last;
    }
}
