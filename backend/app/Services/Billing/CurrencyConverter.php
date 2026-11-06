<?php

namespace App\Services\Billing;

use App\Models\ExchangeRate;
use App\Models\Hotel;
use App\Support\HotelClock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/** Uses the latest exchange rate the hotel entered by hand (workflows.md W9). */
class CurrencyConverter
{
    public function rate(string $from, string $to): string
    {
        if ($from === $to) {
            return '1';
        }
        $rate = ExchangeRate::where('from_currency', $from)->where('to_currency', $to)
            ->where('effective_date', '<=', self::today())->orderByDesc('effective_date')->orderByDesc('id')->value('rate');
        if (! $rate) {
            throw ValidationException::withMessages(['currency' => "Enter today's {$from} → {$to} rate in Settings first."]);
        }

        return (string) $rate;
    }

    /** The hotel's today (rates are entered per hotel day). */
    private static function today(): string
    {
        $id = app(TenantContext::class)->hotelId();

        return HotelClock::now($id ? Hotel::find($id) : null)->toDateString();
    }
}
