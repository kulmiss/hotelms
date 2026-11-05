<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Cash of one currency in a cashier shift. Scoped through its session. */
#[Fillable(['cashier_session_id', 'currency', 'opening_amount', 'expected_amount', 'counted_amount', 'difference'])]
class CashierSessionBalance extends Model
{
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<CashierSession, $this> */
    public function session(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'cashier_session_id');
    }

    protected function casts(): array
    {
        return ['opening_amount' => 'decimal:2', 'expected_amount' => 'decimal:2', 'counted_amount' => 'decimal:2', 'difference' => 'decimal:2'];
    }
}
