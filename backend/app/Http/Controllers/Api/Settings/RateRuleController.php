<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Branch;
use App\Models\GuestType;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Services\PriceCalculator;
use App\Support\HotelClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Weekend, season and guest-type prices, plus a price preview. */
class RateRuleController extends ResourceController
{
    protected function model(): string
    {
        return RateRule::class;
    }

    /** Room types of the branches this person works in. */
    private function myTypes(): array
    {
        return RoomType::whereIn('branch_id', request()->user()->accessibleBranches()->pluck('id'))->pluck('id')->all();
    }

    /** A rule for every room type touches all branches: only for people who work in all of them (e.g. the Hotel Admin). */
    private function allBranches(): bool
    {
        $user = request()->user();

        return $user->isHotelAdmin() || $user->accessibleBranches()->count() >= Branch::count();
    }

    protected function messages(): array
    {
        return [
            'room_type_id.required' => 'Choose a room type of your branch.', 'room_type_id.in' => 'Choose a room type of your branch.',
            'adjustment.in' => 'A long-stay, early-bird or last-minute rule changes the price by a percent or an amount.',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPlan($request);

        return parent::store($request);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->checkPlan($request);

        return parent::update($request, $id);
    }

    /** Long-stay, early-bird, last-minute and minimum stay are not on the Free plan (switch feature.smart_pricing). */
    private function checkPlan(Request $request): void
    {
        if (app(PriceCalculator::class)->smartPricing()) {
            return;
        }
        if (in_array($request->input('rule_type'), RateRule::STAY_TYPES, true)) {
            $this->refuse('rule_type', 'Long-stay, early-bird and last-minute prices are not in your plan. Upgrade to use them.');
        }
        if (filled($request->input('min_stay'))) {
            $this->refuse('min_stay', 'A minimum stay is not in your plan. Upgrade to use it.');
        }
    }

    protected function query(): Builder
    {
        return RateRule::query()
            ->where(fn ($q) => $q->whereIn('room_type_id', $this->myTypes())->when($this->allBranches(), fn ($w) => $w->orWhereNull('room_type_id')))
            ->orderByDesc('priority')->orderBy('name');
    }

    protected function find(string $id): Model
    {
        return $this->query()->findOrFail($id); // other branches' rules → 404
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'rule_type' => ['required', Rule::in([...RateRule::NIGHT_TYPES, ...RateRule::STAY_TYPES])],
            'room_type_id' => [$this->allBranches() ? 'nullable' : 'required', 'integer', Rule::in($this->myTypes())],
            'start_date' => ['nullable', 'date', Rule::requiredIf($request->input('rule_type') === 'season')],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', Rule::requiredIf($request->input('rule_type') === 'season')],
            'days_of_week' => ['nullable', 'array', Rule::requiredIf($request->input('rule_type') === 'weekend')],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'guest_type_id' => ['nullable', 'integer', Rule::in(GuestType::pluck('id')->all()), Rule::requiredIf($request->input('rule_type') === 'guest_type')],
            'min_nights' => ['nullable', 'integer', 'between:2,365', Rule::requiredIf($request->input('rule_type') === 'long_stay')],
            'days_before' => ['nullable', 'integer', 'between:0,365', Rule::requiredIf(in_array($request->input('rule_type'), ['early_bird', 'last_minute'], true))],
            'min_stay' => ['nullable', 'integer', 'between:2,60'],
            // A whole-stay rule changes each night's price, it cannot set one fixed price.
            'adjustment' => ['required', Rule::in(in_array($request->input('rule_type'), RateRule::STAY_TYPES, true) ? ['percent', 'amount'] : ['fixed_price', 'percent', 'amount'])],
            'value' => ['required', 'decimal:0,2', $request->input('adjustment') === 'fixed_price' ? 'min:0' : 'min:-9999999', 'max:99999999'],
            'priority' => ['required', 'integer', 'between:0,1000'],
            'is_active' => ['boolean'],
        ];
    }

    /** Price for each night of a stay, showing which rule was used. */
    public function preview(Request $request, PriceCalculator $prices): JsonResponse
    {
        $request->validate(['from' => ['required', 'date']]);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from', 'before_or_equal:'.Carbon::parse($request->input('from'))->addDays(31)->toDateString()],
            'guest_type_id' => ['nullable', 'integer'],
        ]);
        $type = RoomType::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($data['room_type_id']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        return response()->json(['data' => $prices->stay($type, $from, $to, $data['guest_type_id'] ?? null, HotelClock::now($request->user()->hotel)) + ['min_stay' => $prices->minStayProblem($type, $from, $to)]]);
    }
}
