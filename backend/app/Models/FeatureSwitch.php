<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One switch setting (platform table, not hotel-scoped). */
#[Fillable(['key', 'level', 'target_id', 'value', 'starts_at', 'ends_at', 'reason', 'changed_by'])]
class FeatureSwitch extends Model
{
    public const LEVELS = ['everyone', 'plan', 'hotel'];

    protected function casts(): array
    {
        return ['value' => 'json', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** In force right now (scheduled ones not yet, expired gifts no more). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
