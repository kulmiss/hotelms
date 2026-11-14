<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message to a guest (the message log of a booking): email, SMS, or a WhatsApp / SMS-app message opened by staff. */
#[Fillable(['hotel_id', 'reservation_id', 'booking_request_id', 'guest_id', 'key', 'channel', 'language', 'to', 'status', 'error', 'sent_by'])]
class GuestMessage extends Model
{
    use BelongsToHotel;

    public const CHANNELS = ['email', 'sms', 'whatsapp', 'sms_app'];

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
