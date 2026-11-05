<?php

namespace App\Http\Resources;

use App\Models\Hotel;
use App\Services\HotelClosing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Hotel */
class HotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'default_currency' => $this->default_currency,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'is_demo' => $this->is_demo,
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? ['id' => $this->plan->id, 'name' => $this->plan->name] : null),
            'trial_plan' => $this->whenLoaded('trialPlan', fn () => $this->trialPlan ? ['id' => $this->trialPlan->id, 'name' => $this->trialPlan->name] : null),
            'billing_cycle' => $this->billing_cycle,
            'custom_price' => $this->custom_price,
            'trial_ends_at' => $this->trial_ends_at?->toDateString(),
            'subscription_ends_at' => $this->subscription_ends_at?->toDateString(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspend_reason' => $this->suspend_reason,
            'closed_at' => $this->deleted_at?->toIso8601String(),
            'deleted_for_good_on' => $this->deleted_at?->copy()->addDays(HotelClosing::KEEP_DAYS)->toDateString(),
            'branches_count' => $this->whenCounted('branches'),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
