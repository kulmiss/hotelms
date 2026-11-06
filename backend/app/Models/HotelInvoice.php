<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A HotelMS subscription invoice to a hotel (platform table; hotels see their own). */
#[Fillable(['hotel_id', 'number', 'plan_id', 'billing_cycle', 'period_start', 'period_end', 'rooms', 'amount', 'currency', 'status', 'due_date', 'emailed_at', 'subscription_payment_id', 'created_by'])]
class HotelInvoice extends Model
{
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'emailed_at' => 'datetime'];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class)->withTrashed();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id, 'number' => $this->number, 'plan' => $this->plan?->name, 'billing_cycle' => $this->billing_cycle,
            'period_start' => $this->period_start->toDateString(), 'period_end' => $this->period_end->toDateString(), 'rooms' => $this->rooms,
            'amount' => $this->amount, 'currency' => $this->currency, 'status' => $this->status, 'due_date' => $this->due_date->toDateString(),
            'emailed_at' => $this->emailed_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
