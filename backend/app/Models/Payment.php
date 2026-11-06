<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['hotel_id', 'branch_id', 'reservation_id', 'invoice_id', 'payment_method_id', 'cashier_session_id', 'type', 'amount', 'currency', 'exchange_rate', 'amount_in_base', 'reference', 'refund_reason', 'paid_at', 'received_by'])]
class Payment extends Model
{
    use BelongsToHotel, LogsActivity;

    public const TYPES = ['deposit', 'payment', 'refund'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'amount_in_base' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function activityName(): string
    {
        return ucfirst($this->type).' '.$this->amount.' '.$this->currency;
    }
}
