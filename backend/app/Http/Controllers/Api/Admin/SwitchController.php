<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\FeatureSwitch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Services\ActivityLogger;
use App\Services\Switches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Super Admin → Switches (W4): everyone / plan / one hotel, gifts with an end date, scheduled changes, banners. */
class SwitchController extends Controller
{
    public function __construct(private ActivityLogger $log) {}

    public function index(): JsonResponse
    {
        $rows = FeatureSwitch::orderBy('key')->orderBy('id')->get();
        $hotels = Hotel::whereIn('id', $rows->where('level', 'hotel')->pluck('target_id'))->pluck('name', 'id');

        return response()->json([
            'registry' => collect(Switches::registry())->map(fn ($d, $k) => ['key' => $k] + $d)->values(),
            'plans' => Plan::orderBy('sort')->get(['id', 'name']),
            'switches' => $rows->map(fn (FeatureSwitch $s) => [
                'id' => $s->id, 'key' => $s->key, 'level' => $s->level, 'target_id' => $s->target_id,
                'target_name' => $s->level === 'hotel' ? ($hotels[$s->target_id] ?? '—') : null,
                'value' => $s->value, 'starts_at' => $s->starts_at?->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(),
                'reason' => $s->reason, 'scheduled' => $s->starts_at?->isFuture() ?? false, 'expired' => $s->ends_at?->isPast() ?? false,
            ])->values(),
            'banners' => Banner::latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(Switches::registry()))],
            'level' => ['required', Rule::in(FeatureSwitch::LEVELS)],
            'target_id' => ['nullable', 'integer', 'required_unless:level,everyone'],
            'value' => ['present'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $def = Switches::registry()[$data['key']];
        $value = $data['value'];
        if ($def['type'] === 'bool' && ! is_bool($value)) {
            throw ValidationException::withMessages(['value' => 'Choose on or off.']);
        }
        if ($def['type'] === 'int' && $value !== null && (! is_numeric($value) || (int) $value < 0 || (int) $value > 100000)) {
            throw ValidationException::withMessages(['value' => 'Type a number (0 – 100000), or leave it empty for the plan limit.']);
        }
        $target = $data['level'] === 'everyone' ? null : (int) $data['target_id'];
        if ($data['level'] === 'plan' && ! Plan::whereKey($target)->exists()) {
            throw ValidationException::withMessages(['target_id' => 'Choose a plan.']);
        }
        if ($data['level'] === 'hotel' && ! Hotel::whereKey($target)->exists()) {
            throw ValidationException::withMessages(['target_id' => 'Choose a hotel.']);
        }
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && strtotime($data['ends_at']) <= strtotime($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }

        // A change now replaces the current setting at that level; a scheduled one waits next to it.
        $old = FeatureSwitch::where('key', $data['key'])->where('level', $data['level'])->where('target_id', $target)->whereNull('starts_at')->first();
        if (empty($data['starts_at'])) {
            FeatureSwitch::where('key', $data['key'])->where('level', $data['level'])->where('target_id', $target)->whereNull('starts_at')->delete();
        }
        $switch = FeatureSwitch::create([
            'key' => $data['key'], 'level' => $data['level'], 'target_id' => $target,
            'value' => $def['type'] === 'int' ? ($value === null ? null : (int) $value) : (bool) $value,
            'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
            'reason' => $data['reason'] ?? null, 'changed_by' => $request->user()->id,
        ]);
        app(Switches::class)->flush();
        $this->log->log('switch.changed', "Switch {$data['key']} ({$data['level']}".($target ? " #{$target}" : '').') set to '.json_encode($switch->value), null, [
            'old' => $old?->value, 'new' => $switch->value, 'starts_at' => $switch->starts_at, 'ends_at' => $switch->ends_at, 'reason' => $switch->reason,
        ]);

        return response()->json(['data' => $switch], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $switch = FeatureSwitch::findOrFail($id);
        $switch->delete();
        app(Switches::class)->flush();
        $this->log->log('switch.removed', "Switch {$switch->key} ({$switch->level}) removed – back to the next level", null, ['old' => $switch->value]);

        return response()->json(['message' => 'Removed.']);
    }

    /** One hotel: every switch with its value and where it comes from (plan / gift / everyone / this hotel). */
    public function forHotel(Hotel $hotel, Switches $switches): JsonResponse
    {
        return response()->json(['data' => collect($switches->all($hotel))->map(fn ($v, $k) => ['key' => $k] + $v)->values()]);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:300'],
            'tone' => ['required', Rule::in(['info', 'warn', 'danger'])],
            'hotel_ids' => ['nullable', 'array'], 'hotel_ids.*' => ['integer'],
            'plan_ids' => ['nullable', 'array'], 'plan_ids.*' => ['integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);
        $banner = Banner::create($data + ['created_by' => $request->user()->id]);
        $this->log->log('banner.created', 'Banner: '.$data['message'], null);

        return response()->json(['data' => $banner], 201);
    }

    public function destroyBanner(string $id): JsonResponse
    {
        Banner::findOrFail($id)->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
