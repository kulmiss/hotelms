<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\User;
use App\Services\HotelOnboarding;
use App\Services\HotelStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class HotelController extends Controller
{
    public function __construct(private HotelStatus $status) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(Hotel::STATUSES)],
            'plan_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $hotels = Hotel::query()
            ->with('plan')
            ->withCount(['branches', 'users'])
            ->when($request->string('search')->value(), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%"));
            })
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('plan_id'), fn ($q, $plan) => $q->where('plan_id', $plan))
            ->orderBy('name')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return HotelResource::collection($hotels)->additional([
            'counts' => Hotel::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n),
        ]);
    }

    public function store(Request $request, HotelOnboarding $onboarding): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:60'],
            'default_currency' => ['required', 'string', 'size:3'],
            'plan_id' => ['required', 'exists:plans,id'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'custom_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'between:0,365'],
            'trial_plan_id' => ['nullable', 'exists:plans,id'],
            'admin.name' => ['required', 'string', 'max:120'],
            'admin.email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'admin.phone' => ['nullable', 'string', 'max:30'],
        ]);

        $admin = $data['admin'];
        $trialDays = $data['trial_days'] ?? null;
        unset($data['admin'], $data['trial_days']);

        $result = $onboarding->create($data, $admin, $trialDays);

        return response()->json([
            'data' => new HotelResource($result['hotel']->load(['plan', 'trialPlan'])),
            'admin' => [
                'name' => $result['admin']->name,
                'email' => $result['admin']->email,
                'temporary_password' => $result['temporary_password'],
                'email_sent' => $result['email_sent'],
            ],
        ], 201);
    }

    public function show(Hotel $hotel): JsonResponse
    {
        $hotel->load(['plan', 'trialPlan'])->loadCount(['branches', 'users']);

        return response()->json([
            'data' => new HotelResource($hotel),
            'branches' => $hotel->branches()->orderBy('name')->get(['id', 'name', 'city', 'status']),
            'admins' => User::where('hotel_id', $hotel->id)->orderBy('id')->limit(5)
                ->get(['id', 'name', 'email', 'phone', 'last_login_at', 'must_change_password']),
            'payments' => $hotel->subscriptionPayments()->with('plan:id,name', 'recorder:id,name')->latest('paid_at')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, Hotel $hotel): HotelResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:60'],
            'default_currency' => ['sometimes', 'required', 'string', 'size:3'],
            'plan_id' => ['sometimes', 'required', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'required', Rule::in(['monthly', 'yearly'])],
            'custom_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
        ]);

        $hotel->update($data);

        return new HotelResource($hotel->load(['plan', 'trialPlan']));
    }

    public function suspend(Request $request, Hotel $hotel): HotelResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $hotel->forceFill(['suspended_at' => now(), 'suspend_reason' => $data['reason']])->save();
        $this->status->refresh($hotel);

        return new HotelResource($hotel->load(['plan', 'trialPlan']));
    }

    public function activate(Hotel $hotel): HotelResource
    {
        $hotel->forceFill(['suspended_at' => null, 'suspend_reason' => null])->save();
        $this->status->refresh($hotel);

        return new HotelResource($hotel->load(['plan', 'trialPlan']));
    }

    /** Extend or shorten one hotel's free trial (owner decision: per hotel). */
    public function setTrial(Request $request, Hotel $hotel): HotelResource
    {
        $data = $request->validate([
            'trial_ends_at' => ['required', 'date'],
            'trial_plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $hotel->trial_ends_at = Carbon::parse($data['trial_ends_at'])->toDateString();
        if (array_key_exists('trial_plan_id', $data)) {
            $hotel->trial_plan_id = $data['trial_plan_id'];
        }
        $hotel->save();
        $this->status->refresh($hotel);

        return new HotelResource($hotel->load(['plan', 'trialPlan']));
    }
}
