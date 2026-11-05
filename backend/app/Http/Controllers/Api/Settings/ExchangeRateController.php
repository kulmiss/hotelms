<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\HotelCurrency;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Exchange rates are entered by hand and kept as history (never edited). */
class ExchangeRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ExchangeRate::query()
                ->when($request->input('currency'), fn ($q, $c) => $q->where('from_currency', $c))
                ->latest('effective_date')->latest('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $codes = HotelCurrency::pluck('code')->all();
        $main = HotelCurrency::where('is_default', true)->value('code');

        $data = $request->validate([
            'from_currency' => ['required', Rule::in($codes), Rule::notIn([$main])],
            'rate' => ['required', 'decimal:0,6', 'gt:0', 'max:1000000'],
            'effective_date' => ['nullable', 'date'],
        ]);

        $rate = ExchangeRate::create([
            'from_currency' => $data['from_currency'],
            'to_currency' => $main,
            'rate' => $data['rate'],
            'effective_date' => $data['effective_date'] ?? HotelClock::now($request->user()->hotel)->toDateString(),
            'entered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $rate], 201);
    }
}
