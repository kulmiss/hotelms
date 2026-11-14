<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Hotel;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Room prices per night (claude/workflows.md → pricing, Q28).
 *
 * 1. The room type's nightly price is the base.
 * 2. Night rules (weekend, season, holiday, guest type): of all active rules that match the night (dates,
 *    weekday, holiday list, room type, guest type), only the one with the highest priority applies (ties: newest).
 * 3. Stay rules (long stay, early-bird, last-minute; not on the Free plan): of those that match the whole stay
 *    (number of nights, days between booking and arrival, arrival in the rule's dates), the one with the highest
 *    priority is added on top of every night.
 * - fixed_price: the night costs exactly this (night rules only)
 * - percent:     ± percent (use a negative value for a discount)
 * - amount:      ± amount per night
 * A price never goes below zero.
 */
class PriceCalculator
{
    /** @var array<int, Collection<int, RateRule>> rules per hotel (never mixed between hotels) */
    private array $rules = [];

    /** @var array<int, array<string, true>> active holiday dates per hotel */
    private array $holidays = [];

    /** @var array<int, bool> */
    private array $smart = [];

    /** What was read belongs to one request (a controller, and so this object, can live longer). */
    private ?string $request = null;

    /** @return array{amount: string, base: string, rule: ?array{id: int, name: string}} */
    public function night(RoomType $type, CarbonInterface $night, ?int $guestTypeId = null): array
    {
        $base = (string) $type->nightly_price;
        $rule = $this->matchingRule($type, $night, $guestTypeId);

        return [
            'amount' => $rule ? $this->apply($base, $rule) : $base,
            'base' => $base,
            'rule' => $rule ? ['id' => $rule->id, 'name' => $rule->name] : null,
        ];
    }

    /**
     * Price of a stay, night by night (check-out day not charged).
     * $bookedOn: the day the booking is made (hotel time), for early-bird and last-minute rules; default today.
     *
     * @return array{total: string, currency: string, nights: list<array{date: string, amount: string, rule: ?array{id: int, name: string}, stay_rule?: array{id: int, name: string}}>}
     */
    public function stay(RoomType $type, CarbonInterface $checkIn, CarbonInterface $checkOut, ?int $guestTypeId = null, ?CarbonInterface $bookedOn = null): array
    {
        $in = Carbon::instance($checkIn)->startOfDay();
        $out = Carbon::instance($checkOut)->startOfDay();
        $count = (int) max(0, $in->diffInDays($out));
        $stayRule = $count > 0 ? $this->stayRule($type, $in, $count, Carbon::instance($bookedOn ?? now())->startOfDay()) : null;

        $nights = [];
        $total = '0.00';
        for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
            $price = $this->night($type, $d, $guestTypeId);
            $night = ['date' => $d->toDateString(), 'amount' => $price['amount'], 'rule' => $price['rule']];
            if ($stayRule) {
                $night['amount'] = $this->apply($price['amount'], $stayRule);
                $night['stay_rule'] = ['id' => $stayRule->id, 'name' => $stayRule->name];
                // One name for the line: "Weekend · Long stay".
                $night['rule'] = ['id' => $price['rule']['id'] ?? $stayRule->id, 'name' => mb_substr(trim(($price['rule']['name'] ?? '').' · '.$stayRule->name, ' ·'), 0, 60)];
            }
            $nights[] = $night;
            $total = Money::add($total, $night['amount']);
        }

        return ['total' => $total, 'currency' => $type->currency, 'nights' => $nights];
    }

    /**
     * The longest minimum stay of the seasons and holidays this stay touches, when the stay is shorter
     * (not on the Free plan). @return array{name: string, nights: int}|null
     */
    public function minStayProblem(RoomType $type, CarbonInterface $checkIn, CarbonInterface $checkOut): ?array
    {
        if (! $this->smartPricing()) {
            return null;
        }
        $in = Carbon::instance($checkIn)->startOfDay();
        $out = Carbon::instance($checkOut)->startOfDay();
        $count = (int) $in->diffInDays($out);
        $worst = null;
        for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
            foreach ($this->rules() as $r) {
                if (! $r->min_stay || ! in_array($r->rule_type, ['season', 'holiday'], true) || ! $this->nightMatches($r, $type, $d, null)) {
                    continue;
                }
                if ($r->min_stay > $count && (! $worst || $r->min_stay > $worst['nights'])) {
                    $worst = ['name' => $r->name, 'nights' => $r->min_stay];
                }
            }
        }

        return $worst;
    }

    private function matchingRule(RoomType $type, CarbonInterface $night, ?int $guestTypeId): ?RateRule
    {
        return $this->rules()
            ->filter(fn (RateRule $r) => in_array($r->rule_type, RateRule::NIGHT_TYPES, true) && $this->nightMatches($r, $type, $night, $guestTypeId))
            ->sortBy([['priority', 'desc'], ['id', 'desc']])
            ->first();
    }

    private function nightMatches(RateRule $r, RoomType $type, CarbonInterface $night, ?int $guestTypeId): bool
    {
        $date = $night->toDateString();
        if ($r->room_type_id !== null && $r->room_type_id !== $type->id) {
            return false;
        }
        if ($r->start_date && $date < $r->start_date->toDateString()) {
            return false;
        }
        if ($r->end_date && $date > $r->end_date->toDateString()) {
            return false;
        }
        if ($r->days_of_week && ! in_array($night->isoWeekday(), array_map('intval', $r->days_of_week), true)) {
            return false;
        }
        if ($r->rule_type === 'holiday' && ! isset($this->holidayDates()[$date])) {
            return false;
        }
        if ($r->rule_type === 'guest_type' && ($guestTypeId === null || $r->guest_type_id !== $guestTypeId)) {
            return false;
        }

        return true;
    }

    private function stayRule(RoomType $type, Carbon $arrival, int $nights, Carbon $bookedOn): ?RateRule
    {
        if (! $this->smartPricing()) {
            return null;
        }
        $daysBefore = (int) $bookedOn->diffInDays($arrival, false);
        $date = $arrival->toDateString();

        return $this->rules()
            ->filter(function (RateRule $r) use ($type, $nights, $daysBefore, $date) {
                if (! in_array($r->rule_type, RateRule::STAY_TYPES, true) || $r->adjustment === 'fixed_price') {
                    return false;
                }
                if ($r->room_type_id !== null && $r->room_type_id !== $type->id) {
                    return false;
                }
                if (($r->start_date && $date < $r->start_date->toDateString()) || ($r->end_date && $date > $r->end_date->toDateString())) {
                    return false; // arrival outside the rule's dates
                }

                return match ($r->rule_type) {
                    'long_stay' => $nights >= (int) $r->min_nights,
                    'early_bird' => $daysBefore >= (int) $r->days_before,
                    'last_minute' => $daysBefore >= 0 && $daysBefore <= (int) $r->days_before,
                    default => false,
                };
            })
            ->sortBy([['priority', 'desc'], ['id', 'desc']])
            ->first();
    }

    private function apply(string $base, RateRule $rule): string
    {
        $value = (string) $rule->value;

        $price = match ($rule->adjustment) {
            'fixed_price' => $value,
            'percent' => Money::add($base, Money::mul($base, bcdiv($value, '100', 6))),
            'amount' => Money::add($base, $value),
            default => $base,
        };

        return Money::compare($price, '0') < 0 ? '0.00' : Money::round($price);
    }

    private function hotelId(): int
    {
        $attrs = request()->attributes;
        if (! $attrs->has('prices.read')) {
            $attrs->set('prices.read', bin2hex(random_bytes(8)));
        }
        $request = (string) $attrs->get('prices.read');
        if ($request !== $this->request) {
            [$this->rules, $this->holidays, $this->smart, $this->request] = [[], [], [], $request];
        }

        return (int) app(TenantContext::class)->hotelId();
    }

    /** @return Collection<int, RateRule> active rules of the current hotel (loaded once) */
    private function rules(): Collection
    {
        return $this->rules[$this->hotelId()] ??= RateRule::where('is_active', true)->get();
    }

    /** @return array<string, true> */
    private function holidayDates(): array
    {
        return $this->holidays[$this->hotelId()] ??= Holiday::where('is_active', true)->pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();
    }

    /** Long-stay, early-bird, last-minute and minimum stay: not on the Free plan (switch feature.smart_pricing). */
    public function smartPricing(): bool
    {
        $id = $this->hotelId();
        if (! isset($this->smart[$id])) {
            $hotel = Hotel::find($id);
            $this->smart[$id] = $hotel ? app(Switches::class)->on($hotel, 'feature.smart_pricing') : false;
        }

        return $this->smart[$id];
    }
}
