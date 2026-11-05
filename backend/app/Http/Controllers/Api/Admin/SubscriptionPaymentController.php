<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Services\Subscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPaymentController extends Controller
{
    public function __construct(private Subscriptions $subscriptions) {}

    /** Suggested amount for a payment (the Super Admin can still change it). */
    public function quote(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'periods' => ['required', 'integer', 'between:1,24'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        return response()->json([
            'amount' => $this->subscriptions->quote($hotel, $plan, $data['billing_cycle'], (int) $data['periods']),
            'currency' => $plan->currency,
            'months' => $this->subscriptions->monthsCovered($data['billing_cycle'], (int) $data['periods']),
            'rooms' => $this->subscriptions->billableRooms($hotel),
        ]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'method' => ['required', Rule::in(SubscriptionPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'periods' => ['required', 'integer', 'between:1,24'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $this->subscriptions->recordPayment($hotel, $data, $request->user());

        return response()->json([
            'data' => $payment,
            'hotel' => new HotelResource($hotel->fresh()->load(['plan', 'trialPlan'])),
        ], 201);
    }
}
