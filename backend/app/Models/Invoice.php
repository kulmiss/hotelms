<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'hotel_id', 'branch_id', 'reservation_id', 'reservation_room_id', 'guest_id', 'number', 'currency', 'subtotal', 'discount_total',
    'tax_total', 'total', 'paid_total', 'balance', 'status', 'issued_at', 'outstanding_approved_by', 'outstanding_approved_at',
    'voided_by', 'voided_at', 'void_reason',
])]
class Invoice extends Model
{
    use BelongsToHotel, LogsActivity;

    public const STATUSES = ['open', 'partially_paid', 'paid', 'outstanding', 'void'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'paid_total' => 'decimal:2', 'balance' => 'decimal:2', 'issued_at' => 'datetime',
            'outstanding_approved_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('service_date')->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function activityName(): string
    {
        return $this->number;
    }
}
