<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a support request. "note" is internal to HotelMS and never shown to the hotel. */
#[Fillable(['support_request_id', 'user_id', 'kind', 'body'])]
class SupportMessage extends Model
{
    public const KINDS = ['message', 'reply', 'note', 'system'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
