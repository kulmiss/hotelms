<?php

namespace App\Services;

use App\Support\Tenancy\TenantContext;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Room prices per night (claude/workflows.md → pricing).
 *
 * The room type's nightly price is the base. Of all active rate rules that
 * match the night (date range, weekday, room type, guest type), only the one
 * with the highest priority applies (ties: the newest rule):
 * - fixed_price: the night costs exactly this
 * - percent:     base ± percent (use a negative value for a discount)
 * - amount:      base ± amount
 * A price never goes below zero.
 */
class PriceCalculator
{
    /** @var array<int, Collection<int, RateRule>> rules per hotel (never mixed between hotels) */
    private array $rules = [];

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
     *
     * @return array{total: string, currency: string, nights: list<array{date: string, amount: string, rule: ?array{id: int, name: string}}>}
     */
    public function stay(RoomType $type, CarbonInterface $checkIn, CarbonInterface $checkOut, ?int $guestTypeId = null): array
    {
        $nights = [];
        $total = '0.00';

        for ($d = Carbon::instance($checkIn)->startOfDay(); $d->lt(Carbon::instance($checkOut)->startOfDay()); $d->addDay()) {
            $price = $this->night($type, $d, $guestTypeId);
            $nights[] = ['date' => $d->toDateString(), 'amount' => $price['amount'], 'rule' => $price['rule']];
            $total = Money::add($total, $price['amount']);
        }

        return ['total' => $total, 'currency' => $type->currency, 'nights' => $nights];
    }

    private function matchingRule(RoomType $type, CarbonInterface $night, ?int $guestTypeId): ?RateRule
    {
        $date = $night->toDateString();
        $weekday = $night->isoWeekday();

        return $this->rules()
            ->filter(function (RateRule $r) use ($type, $date, $weekday, $guestTypeId) {
                if ($r->room_type_id !== null && $r->room_type_id !== $type->id) {
                    return false;
                }
                if ($r->start_date && $date < $r->start_date->toDateString()) {
                    return false;
                }
                if ($r->end_date && $date > $r->end_date->toDateString()) {
                    return false;
                }
                if ($r->days_of_week && ! in_array($weekday, array_map('intval', $r->days_of_week), true)) {
                    return false;
                }
                if ($r->rule_type === 'guest_type' && ($guestTypeId === null || $r->guest_type_id !== $guestTypeId)) {
                    return false;
                }

                return true;
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

    /** @return Collection<int, RateRule> active rules of the current hotel (loaded once) */
    private function rules(): Collection
    {
        return $this->rules[(int) app(TenantContext::class)->hotelId()] ??= RateRule::where('is_active', true)->get();
    }
}
