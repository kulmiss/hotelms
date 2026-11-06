<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo or PDF that shows a payment was made. Stored on the private disk, shown only to the hotel's staff. */
#[Fillable(['hotel_id', 'payment_id', 'path', 'mime', 'size', 'uploaded_by'])]
class PaymentProof extends Model
{
    use BelongsToHotel;

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
