<?php

namespace App\Services\Billing;

use App\Models\Tax;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Taxes for one invoice line (taxes.applies_to: rooms, extras or all).
 * - added tax: amount × rate (on top of the price)
 * - inclusive tax: the part already inside the price: amount × rate / (100 + rate)
 * Discounts carry negative tax so the base goes down too.
 */
class TaxCalculator
{
    /** @var array<int, Collection<int, Tax>> taxes per hotel (never mixed between hotels) */
    private array $taxes = [];

    /** Line types that count as "rooms" for taxes. */
    public const ROOM_TYPES = ['room', 'early_check_in', 'late_check_out', 'early_check_out', 'discount'];

    /** @return list<array{tax_id: int, name: string, rate: string, is_inclusive: bool, amount: string}> */
    public function forLine(string $type, string $amount): array
    {
        if ($type === 'cancellation_fee' || Money::compare($amount, '0') === 0) {
            return [];
        }
        $group = in_array($type, self::ROOM_TYPES, true) ? 'rooms' : 'extras';

        return $this->active()
            ->filter(fn (Tax $t) => $t->applies_to === 'all' || $t->applies_to === $group)
            ->map(function (Tax $t) use ($amount) {
                $rate = (string) $t->rate;
                $tax = $t->is_inclusive
                    ? Money::round(bcdiv(bcmul($amount, $rate, 6), bcadd('100', $rate, 6), 6))
                    : Money::percentOf($amount, $rate);

                return ['tax_id' => $t->id, 'name' => $t->name, 'rate' => $rate, 'is_inclusive' => (bool) $t->is_inclusive, 'amount' => $tax];
            })->values()->all();
    }

    /** @return Collection<int, Tax> */
    private function active(): Collection
    {
        return $this->taxes[(int) app(TenantContext::class)->hotelId()] ??= Tax::where('is_active', true)->orderBy('id')->get();
    }
}
