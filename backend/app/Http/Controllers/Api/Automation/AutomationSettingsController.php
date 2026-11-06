<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\AutomationSetting;
use App\Models\Branch;
use App\Models\GuestType;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Automation\Automations;
use App\Services\Automation\RuleEngine;
use App\Services\HotelModules;
use App\Services\HotelSettings;
use App\Support\Permissions;
use App\Services\Switches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Settings → Automations (Hotel Admin): ready-made automations on/off, quiet hours, and the rule builder. */
class AutomationSettingsController extends Controller
{
    public function __construct(private Automations $automations, private HotelSettings $settings, private ActivityLogger $log) {}

    public function index(Request $request, Switches $switches, HotelModules $modules): JsonResponse
    {
        $hotel = $request->user()->hotel;
        $settings = $this->automations->settings($hotel);
        $on = $modules->enabled($hotel);
        $list = collect(Automations::registry())->reject(fn ($d) => ! empty($d['platform']))->map(function ($def, $key) use ($settings, $switches, $hotel, $on) {
            $allowed = $switches->on($hotel, 'feature.automations') && $switches->on($hotel, "automation.{$key}");
            $module = $def['module'] ?? null;

            return [
                'key' => $key, 'group' => $def['group'], 'enabled' => $settings[$key]['enabled'], 'options' => (object) $settings[$key]['options'],
                'to' => $def['to'], 'urgent' => (bool) ($def['urgent'] ?? false),
                // Why it cannot run (the hotel cannot change these): turned off by HotelMS, or its module is off.
                'blocked' => ! $allowed ? 'hotelms' : ($module && ! in_array($module, $on, true) ? 'module' : null), 'module' => $module,
            ];
        })->values();

        return response()->json([
            'data' => $list,
            'quiet' => $this->settings->group(HotelSettings::QUIET_DEFAULTS),
            'rules' => AutomationRule::orderBy('name')->get(),
            'builder' => [
                'triggers' => RuleEngine::TRIGGERS, 'fields' => RuleEngine::FIELDS, 'ops' => RuleEngine::OPS, 'actions' => RuleEngine::ACTIONS,
                'branches' => Branch::orderBy('name')->get(['id', 'name']),
                'room_types' => RoomType::orderBy('name')->get(['id', 'name', 'branch_id']),
                'guest_types' => GuestType::orderBy('name')->get(['id', 'name']),
                'staff' => User::where('hotel_id', $hotel->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
                'permissions' => Permissions::all(),
            ],
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $def = Automations::registry()[$key] ?? null;
        abort_if(! $def || ! empty($def['platform']), 404);
        $rules = ['enabled' => ['required', 'boolean'], 'options' => ['nullable', 'array']];
        foreach (array_keys($def['options'] ?? []) as $name) {
            $rules["options.{$name}"] = match ($name) {
                'time' => ['sometimes', 'date_format:H:i'],
                'amount' => ['sometimes', 'decimal:0,2', 'min:0', 'max:99999999'],
                'occupancy', 'percent' => ['sometimes', 'integer', 'between:1,100'],
                default => ['sometimes', 'integer', 'between:1,365'],
            };
        }
        $data = $request->validate($rules);
        $options = array_intersect_key($data['options'] ?? [], $def['options'] ?? []);
        AutomationSetting::updateOrCreate(['key' => $key], ['enabled' => $data['enabled'], 'options' => $options ?: null]);
        $this->automations->flush();
        $this->log->log('automation.changed', "Automation \"{$key}\" turned ".($data['enabled'] ? 'on' : 'off'), null, ['options' => $options]);

        return $this->index($request, app(Switches::class), app(HotelModules::class));
    }

    public function quiet(Request $request): JsonResponse
    {
        $data = $request->validate(['quiet_hours' => ['required', 'boolean'], 'quiet_from' => ['required', 'date_format:H:i'], 'quiet_to' => ['required', 'date_format:H:i', 'different:quiet_from']]);
        $this->settings->put($data);

        return response()->json(['quiet' => $this->settings->group(HotelSettings::QUIET_DEFAULTS)]);
    }

    // ------------------------------------------------------------------ rule builder

    /** @return array<string, mixed> */
    private function validRule(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'trigger' => ['required', Rule::in(RuleEngine::TRIGGERS)],
            'conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*.field' => ['required', Rule::in([...array_keys(RuleEngine::FIELDS), 'time'])],
            'conditions.*.op' => ['required', Rule::in(RuleEngine::OPS)],
            'conditions.*.value' => ['required'],
            'actions' => ['required', 'array', 'min:1', 'max:5'],
            'actions.*.type' => ['required', Rule::in(RuleEngine::ACTIONS)],
            'actions.*.message' => ['nullable', 'string', 'max:200'],
            'actions.*.permission' => ['nullable', Rule::in(Permissions::all())],
            'actions.*.user_ids' => ['nullable', 'array'],
            'actions.*.user_ids.*' => ['integer'],
            'actions.*.urgent' => ['nullable', 'boolean'],
            'actions.*.task' => ['nullable', Rule::in(['cleaning', 'inspection', 'maintenance'])],
            'actions.*.status' => ['nullable', Rule::in(['dirty', 'maintenance'])],
        ]);
        foreach ($data['actions'] as $i => $a) {
            if (in_array($a['type'], ['housekeeping_task', 'room_status'], true) && $data['trigger'] !== 'room_status_changed') {
                throw ValidationException::withMessages(["actions.{$i}.type" => 'This action only works with "Room status changed".']);
            }
            if ($a['type'] === 'booking_note' && ! str_starts_with($data['trigger'], 'booking') && ! in_array($data['trigger'], ['checked_in', 'checked_out', 'payment_received'], true)) {
                throw ValidationException::withMessages(["actions.{$i}.type" => 'A booking note needs a booking event.']);
            }
        }
        if ($data['trigger'] === 'daily' && ! collect($data['conditions'] ?? [])->contains('field', 'time')) {
            $data['conditions'][] = ['field' => 'time', 'op' => 'is', 'value' => '08:00'];
        }

        return $data;
    }

    public function storeRule(Request $request): JsonResponse
    {
        $rule = AutomationRule::create($this->validRule($request) + ['created_by' => $request->user()->id]);
        $this->log->log('automation.rule_created', "Automation rule \"{$rule->name}\" created", $rule);

        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);
        $rule->update($this->validRule($request));

        return response()->json(['data' => $rule]);
    }

    public function destroyRule(string $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);
        $rule->delete();
        $this->log->log('automation.rule_deleted', "Automation rule \"{$rule->name}\" deleted");

        return response()->json(['message' => 'Removed.']);
    }

    /** "This would have fired N times in the last 7 days." */
    public function testRule(Request $request, RuleEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'trigger' => ['required', Rule::in(RuleEngine::TRIGGERS)],
            'conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*.field' => ['required', 'string'],
            'conditions.*.op' => ['required', Rule::in(RuleEngine::OPS)],
            'conditions.*.value' => ['required'],
        ]);
        $conditions = array_values(array_filter($data['conditions'] ?? [], fn ($c) => $c['field'] !== 'time'));

        return response()->json(['count' => $engine->test($request->user()->hotel, $data['trigger'], $conditions), 'days' => 7]);
    }
}
