<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Tax of one invoice line, frozen when the line was added. Scoped through its invoice item. */
#[Fillable(['invoice_item_id', 'tax_id', 'name', 'rate', 'is_inclusive', 'amount'])]
class InvoiceItemTax extends Model
{
    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'is_inclusive' => 'boolean', 'amount' => 'decimal:2'];
    }
}
