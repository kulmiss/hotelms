<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'max_rooms', 'price', 'currency', 'billing_cycle', 'modules', 'is_active'])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Hotel, $this> */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }
}
