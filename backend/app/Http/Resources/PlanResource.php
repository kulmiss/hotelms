<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'price_per_room' => $this->price_per_room,
            'minimum_monthly' => $this->minimum_monthly,
            'yearly_free_months' => $this->yearly_free_months,
            'currency' => $this->currency,
            'is_custom' => $this->is_custom,
            'max_rooms' => $this->max_rooms,
            'max_branches' => $this->max_branches,
            'max_staff' => $this->max_staff,
            'modules' => $this->modules ?? [],
            'is_active' => $this->is_active,
            'hotels_count' => $this->whenCounted('hotels'),
        ];
    }
}
