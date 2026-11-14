<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::withCount('hotels')->orderBy('sort')->get());
    }

    public function store(Request $request): PlanResource
    {
        $plan = Plan::create($this->validated($request));

        return new PlanResource($plan);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $plan->update($this->validated($request, $plan));

        return new PlanResource($plan);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'code' => ['nullable', 'alpha_dash', 'max:30', Rule::unique('plans', 'code')->ignore($plan)],
            'description' => ['nullable', 'string', 'max:255'],
            'price_per_room' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'minimum_monthly' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'yearly_free_months' => ['required', 'integer', 'between:0,6'],
            'currency' => ['required', 'string', 'size:3'],
            'is_custom' => ['boolean'],
            'max_rooms' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_staff' => ['nullable', 'integer', 'min:1'],
            'max_storage_mb' => ['nullable', 'integer', 'min:1'],
            'is_public' => ['boolean'],
            'is_recommended' => ['boolean'],
            'modules' => ['array'],
            'modules.*' => [Rule::in(Plan::MODULES)],
            'is_active' => ['boolean'],
            'sort' => ['integer', 'min:0'],
        ]);
    }
}
