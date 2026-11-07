<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'invoice_id', 'type', 'description', 'quantity', 'unit_price', 'amount', 'discount_percent', 'is_waived', 'waived_by', 'reservation_room_id', 'charge_type_id', 'service_date', 'created_by'])]
class InvoiceItem extends Model
{
    use BelongsToHotel, LogsActivity;

    public const TYPES = ['room', 'extra', 'early_check_in', 'late_check_out', 'early_check_out', 'cancellation_fee', 'discount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'discount_percent' => 'decimal:2',
            'is_waived' => 'boolean', 'service_date' => 'date:Y-m-d',
        ];
    }

    /** @return HasMany<InvoiceItemTax, $this> */
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceItemTax::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function activityName(): string
    {
        return $this->description.' ('.$this->amount.')';
    }
}
