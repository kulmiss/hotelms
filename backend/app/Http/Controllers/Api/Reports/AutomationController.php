<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\CustomReport;
use App\Models\ReportSchedule;
use App\Models\SavedView;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Notify\Telegram;
use App\Services\Reports\Automation;
use App\Services\Reports\CustomReportEngine;
use App\Support\HotelClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Saved views, custom reports, scheduled reports, smart alerts and Telegram linking (reports.md 5, 15–17). */
class AutomationController extends Controller
{
    public function __construct(private Automation $automation) {}

    // ------------------------------------------------------------ saved views

    public function views(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->visible(SavedView::query(), $user, 'user_id')
            ->when($request->input('report_key'), fn ($q, $k) => $q->where('report_key', $k))
            ->orderByDesc('is_pinned')->orderBy('name')->get()
            ->map(fn (SavedView $v) => $v->toArray() + ['mine' => $v->user_id === $user->id])]);
    }

    public function saveView(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'filters' => ['required', 'array'],
            'filters.from' => ['nullable', 'date'], 'filters.to' => ['nullable', 'date'], 'filters.compare' => ['nullable', 'in:prev,ly,none'], 'filters.branch_id' => ['nullable', 'integer'],
            'is_pinned' => ['boolean'],
            'shared_with_roles' => ['nullable', 'array'], 'shared_with_roles.*' => ['integer'],
        ]);
        $data['filters'] = array_intersect_key($data['filters'], array_flip(['from', 'to', 'compare', 'branch_id', 'preset']));
        $view = $id ? SavedView::where('user_id', $request->user()->id)->findOrFail($id) : new SavedView(['user_id' => $request->user()->id]);
        $view->fill($data)->save();

        return response()->json(['data' => $view->fresh()], $id ? 200 : 201);
    }

    public function deleteView(Request $request, string $id): JsonResponse
    {
        SavedView::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ------------------------------------------------------------ custom reports

    public function customReports(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->visible(CustomReport::query(), $user, 'created_by')->orderBy('name')->get()->map(fn (CustomReport $r) => $r->toArray() + ['mine' => $r->created_by === $user->id]),
            'measures' => CustomReportEngine::MEASURES,
            'groups' => CustomReportEngine::GROUPS,
        ]);
    }

    public function saveCustomReport(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate($this->customRules() + ['name' => ['required', 'string', 'max:80'], 'chart_type' => ['required', 'in:table,bar'], 'shared_with_roles' => ['nullable', 'array'], 'shared_with_roles.*' => ['integer']]);
        $report = $id ? CustomReport::where('created_by', $request->user()->id)->findOrFail($id) : new CustomReport(['created_by' => $request->user()->id]);
        $report->fill($data)->save();

        return response()->json(['data' => $report->fresh()], $id ? 200 : 201);
    }

    public function deleteCustomReport(Request $request, string $id): JsonResponse
    {
        CustomReport::where('created_by', $request->user()->id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** Run a report definition (saved or not) for a period. */
    public function runCustom(Request $request): JsonResponse
    {
        $data = $request->validate($this->customRules() + ['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'integer']]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 400) {
            throw ValidationException::withMessages(['from' => 'Choose at most 400 days.']);
        }
        $ids = $request->user()->accessibleBranches()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $b = (int) ($data['branch_id'] ?? 0);
        $engine = new CustomReportEngine($request->user()->hotel, $b && in_array($b, $ids, true) ? [$b] : $ids);

        return response()->json(['data' => $engine->run($data['measures'], $data['group_by'], $data['from'], $data['to']) + ['currency' => $request->user()->hotel->default_currency ?? 'ETB']]);
    }

    /** @return array<string, mixed> */
    private function customRules(): array
    {
        return [
            'measures' => ['required', 'array', 'min:1', 'max:6'],
            'measures.*' => ['string', Rule::in(CustomReportEngine::MEASURES)],
            'group_by' => ['required', Rule::in(CustomReportEngine::GROUPS)],
        ];
    }

    // ------------------------------------------------------------ schedules

    public function schedules(Request $request): JsonResponse
    {
        $this->automation->ensureDefaults($request->user()->hotel);
        $hotel = $request->user()->hotel;

        return response()->json(['data' => ReportSchedule::orderBy('name')->get()->map(fn (ReportSchedule $s) => $s->toArray() + [
            'next_run_local' => HotelClock::iso($s->next_run_at, $hotel), 'last_sent_local' => HotelClock::iso($s->last_sent_at, $hotel),
        ]), 'reports' => Automation::SCHEDULABLE, 'people' => $this->people($request)]);
    }

    public function saveSchedule(Request $request, ?string $id = null): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'report_key' => ['required', Rule::in(Automation::SCHEDULABLE)],
            'custom_report_id' => ['nullable', 'required_if:report_key,custom', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'day' => ['nullable', 'integer', 'between:1,28'],
            'time' => ['required', 'date_format:H:i'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:email,telegram'],
            'recipients' => ['present', 'array'], 'recipients.*' => ['integer'],
            'extra_emails' => ['nullable', 'array', 'max:10'], 'extra_emails.*' => ['email'],
            'is_active' => ['boolean'],
        ]);
        if ($data['frequency'] === 'weekly' && ($data['day'] ?? 0) > 7) {
            throw ValidationException::withMessages(['day' => 'Choose a weekday (1 = Monday … 7 = Sunday).']);
        }
        if (! empty($data['custom_report_id']) && ! CustomReport::whereKey($data['custom_report_id'])->exists()) {
            throw ValidationException::withMessages(['custom_report_id' => 'Choose a custom report.']);
        }
        if (! empty($data['branch_id']) && ! $user->accessibleBranches()->contains('id', (int) $data['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }
        $data['recipients'] = User::where('hotel_id', $user->hotel_id)->whereIn('id', $data['recipients'])->pluck('id')->all();
        $s = $id ? ReportSchedule::findOrFail($id) : new ReportSchedule(['created_by' => $user->id]);
        $s->fill($data);
        $s->next_run_at = $this->automation->nextRun($s, $user->hotel);
        $s->save();

        return response()->json(['data' => $s->fresh()], $id ? 200 : 201);
    }

    public function deleteSchedule(string $id): JsonResponse
    {
        ReportSchedule::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** Send it now, to check that it arrives. */
    public function testSchedule(Request $request, string $id): JsonResponse
    {
        $problems = $this->automation->send(ReportSchedule::findOrFail($id), $request->user()->hotel);

        return response()->json(['problems' => $problems, 'sent' => $problems === []]);
    }

    // ------------------------------------------------------------ alerts

    public function alertRules(Request $request): JsonResponse
    {
        $this->automation->ensureDefaults($request->user()->hotel);

        return response()->json(['data' => AlertRule::orderBy('id')->get(), 'defaults' => Automation::ALERTS, 'people' => $this->people($request)]);
    }

    public function saveAlertRule(Request $request, string $id): JsonResponse
    {
        $rule = AlertRule::findOrFail($id);
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'threshold' => ['nullable', 'array'], 'threshold.*' => ['numeric', 'min:0', 'max:1000000'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:app,email,telegram'],
            'recipients' => ['present', 'array'], 'recipients.*' => ['integer'],
        ]);
        $data['threshold'] = array_intersect_key($data['threshold'] ?? [], Automation::ALERTS[$rule->type] ?? []) + ($rule->threshold ?? []);
        $data['recipients'] = User::where('hotel_id', $request->user()->hotel_id)->whereIn('id', $data['recipients'])->pluck('id')->all();
        $rule->update($data);

        return response()->json(['data' => $rule->fresh()]);
    }

    /** Alerts for me: rules that name me, or every rule if I manage alerts (or am a Hotel Admin and the rule names nobody). */
    public function alertEvents(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $events = AlertEvent::whereIn('alert_rule_id', $this->visibleRuleIds($user))->when(! $request->boolean('all'), fn ($q) => $q->whereNull('acknowledged_at'))
            ->latest('fired_at')->limit(100)->get()
            ->map(fn (AlertEvent $e) => ['id' => $e->id, 'title' => $e->title, 'link' => $e->link, 'fired_at' => HotelClock::iso($e->fired_at, $hotel), 'acknowledged_at' => HotelClock::iso($e->acknowledged_at, $hotel)]);

        return response()->json(['data' => $events, 'unread' => $events->whereNull('acknowledged_at')->count()]);
    }

    /** Alerts a person may see: managers see all; others only rules sent to them (no list = Hotel Admins). */
    private function visibleRuleIds(User $user): array
    {
        return AlertRule::get()
            ->filter(fn (AlertRule $r) => $user->hasPermission('alerts.manage') || in_array($user->id, array_map('intval', $r->recipients ?? []), true) || (empty($r->recipients) && $user->isHotelAdmin()))
            ->pluck('id')->all();
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $event = AlertEvent::whereIn('alert_rule_id', $this->visibleRuleIds($request->user()))->findOrFail($id);
        $event->update(['acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);

        return response()->json(['message' => 'Done.']);
    }

    // ------------------------------------------------------------ telegram

    public function telegramStatus(Request $request, Telegram $telegram): JsonResponse
    {
        $link = TelegramLink::where('user_id', $request->user()->id)->first();

        return response()->json(['enabled' => $telegram->enabled(), 'bot' => $telegram->botUsername(), 'linked' => (bool) $link?->chat_id, 'chat_name' => $link?->chat_name]);
    }

    public function telegramLink(Request $request, Telegram $telegram): JsonResponse
    {
        if (! $telegram->enabled()) {
            throw ValidationException::withMessages(['telegram' => 'Telegram is not set up yet. Ask the HotelMS team.']);
        }

        return response()->json(['data' => $telegram->linkCode($request->user())]);
    }

    /** Sends "it works" to my linked Telegram. */
    public function telegramTest(Request $request, Telegram $telegram): JsonResponse
    {
        $chat = $telegram->chatFor($request->user());
        if (! $chat) {
            throw ValidationException::withMessages(['telegram' => 'Link your Telegram first.']);
        }
        if (! $telegram->send($chat, 'Test message: Telegram works. You will get your reports and alerts here.')) {
            throw ValidationException::withMessages(['telegram' => 'Telegram did not accept the message. Check the bot token in Super Admin → Settings → Telegram, then try again.']);
        }

        return response()->json(['message' => 'Sent. Check Telegram.']);
    }

    public function telegramUnlink(Request $request): JsonResponse
    {
        TelegramLink::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Unlinked.']);
    }

    // ------------------------------------------------------------ helpers

    /** Mine, or shared with my role. */
    private function visible(Builder $q, User $user, string $owner): Builder
    {
        return $q->where(fn ($w) => $w->where($owner, $user->id)->orWhereJsonContains('shared_with_roles', (int) $user->role_id));
    }

    /** @return list<array{id: int, name: string, email: string}> */
    private function people(Request $request): array
    {
        return User::where('hotel_id', $request->user()->hotel_id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email'])->toArray();
    }
}
