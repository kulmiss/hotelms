<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\HotelInvoice;
use App\Models\HotelModule;
use App\Models\Room;
use App\Services\HotelModules;
use App\Services\HotelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Booking rules, optional modules, attendance methods and subscription view. */
class HotelSettingsController extends Controller
{
    public function __construct(private HotelSettings $settings, private HotelModules $modules) {}

    public function bookingRules(): JsonResponse
    {
        return response()->json(['data' => $this->settings->group(HotelSettings::BOOKING_DEFAULTS)]);
    }

    public function updateBookingRules(Request $request): JsonResponse
    {
        $data = $request->validate([
            'deposit_required' => ['required', 'boolean'],
            'deposit_type' => ['required', Rule::in(['fixed', 'percent', 'first_night'])],
            'deposit_value' => ['required', 'decimal:0,2', 'min:0', $request->input('deposit_type') === 'percent' ? 'max:100' : 'max:9999999'],
            'pending_expiry_hours' => ['required', 'integer', 'between:1,720'],
            'walk_in_payment' => ['required', Rule::in(['full', 'deposit'])],
            'early_check_in_fee_type' => ['required', Rule::in(['fixed', 'percent'])],
            'early_check_in_fee_value' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'late_check_out_fee_type' => ['required', Rule::in(['fixed', 'percent'])],
            'late_check_out_fee_value' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'hourly_min_hours' => ['required', 'integer', 'between:1,12'],
            'hourly_cleaning_minutes' => ['required', 'integer', 'between:0,240'],
        ]);
        $this->settings->put($data);

        return $this->bookingRules();
    }

    public function modules(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->modules->overview($request->user()->hotel)]);
    }

    public function updateModules(Request $request): JsonResponse
    {
        $hotel = $request->user()->hotel;
        $data = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['boolean'],
        ]);

        foreach ($data['modules'] as $module => $on) {
            if ($on && ! in_array($module, $this->modules->available($hotel), true)) {
                return response()->json(['message' => 'This module is not in your plan.', 'code' => 'module_not_in_plan', 'module' => $module], 422);
            }
            HotelModule::updateOrCreate(['module' => $module], ['is_enabled' => (bool) $on]);
        }

        return $this->modules($request);
    }

    public function attendance(): JsonResponse
    {
        return response()->json(['data' => $this->settings->group(HotelSettings::ATTENDANCE_DEFAULTS)]);
    }

    public function updateAttendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attendance_methods' => ['required', 'array', 'min:1'],
            'attendance_methods.*' => [Rule::in(['self', 'kiosk_pin', 'manual'])],
        ]);
        $this->settings->put(['attendance_methods' => array_values(array_unique($data['attendance_methods']))]);

        return $this->attendance();
    }

    /** Read-only plan, trial and paid-until for the Hotel Admin. */
    public function subscription(Request $request): JsonResponse
    {
        $hotel = $request->user()->hotel->load(['plan', 'trialPlan']);

        return response()->json(['data' => [
            'status' => $hotel->status,
            'plan' => $hotel->plan?->only(['name', 'max_rooms', 'max_branches', 'max_staff', 'modules']),
            'trial_plan' => $hotel->trialPlan?->only(['name']),
            'billing_cycle' => $hotel->billing_cycle,
            'trial_ends_at' => $hotel->trial_ends_at?->toDateString(),
            'subscription_ends_at' => $hotel->subscription_ends_at?->toDateString(),
            'usage' => [
                'branches' => $hotel->branches()->count(),
                'staff' => $hotel->users()->where('status', 'active')->count(),
                'rooms' => Room::count(),
            ],
            // What really applies (a Super Admin switch can lift or lower the plan's limits).
            'limits' => ['rooms' => $hotel->limit('rooms'), 'branches' => $hotel->limit('branches'), 'staff' => $hotel->limit('staff')],
            // Invoices from HotelMS (emailed too).
            'invoices' => HotelInvoice::where('hotel_id', $hotel->id)->where('status', '!=', 'void')->with('plan:id,name')->latest('id')->limit(24)->get()->map->present()->values(),
        ]]);
    }
}
