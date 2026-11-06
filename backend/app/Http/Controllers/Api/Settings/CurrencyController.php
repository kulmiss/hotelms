<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\ExchangeRate;
use App\Models\HotelCurrency;
use App\Support\HotelClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Currencies a hotel accepts. The default (main) currency cannot be removed;
 * other currencies need an exchange rate to the main one (entered by hand).
 */
class CurrencyController extends ResourceController
{
    protected function model(): string
    {
        return HotelCurrency::class;
    }

    public function index(): JsonResponse
    {
        $main = HotelCurrency::where('is_default', true)->value('code');
        $today = HotelClock::now(request()->user()->hotel)->toDateString();
        $currencies = HotelCurrency::orderByDesc('is_default')->orderBy('code')->get()->map(function (HotelCurrency $c) use ($main, $today) {
            $rate = $c->code === $main ? null : ExchangeRate::where('from_currency', $c->code)->where('to_currency', $main)
                ->where('effective_date', '<=', $today)->latest('effective_date')->latest('id')->first();

            return $c->toArray() + ['current_rate' => $rate?->rate, 'rate_date' => $rate?->effective_date?->toDateString()];
        });

        return response()->json(['data' => $currencies, 'main' => $main]);
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('hotel_currencies', 'code')->where('hotel_id', $request->user()->hotel_id)->ignore($record)],
            'symbol' => ['required', 'string', 'max:8'],
        ];
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->is_default) {
            $this->refuse('currency', 'The main currency cannot be removed.');
        }
    }
}
