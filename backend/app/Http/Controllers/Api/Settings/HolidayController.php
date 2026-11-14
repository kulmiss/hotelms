<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Holiday;
use App\Services\HotelSettings;
use App\Services\Pricing\EthiopianHolidays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The hotel's holiday list (Settings → Prices → Holidays): "holiday" price rules apply on these nights. */
class HolidayController extends ResourceController
{
    protected function model(): string
    {
        return Holiday::class;
    }

    protected function query(): Builder
    {
        return Holiday::query()->where('date', '>=', now()->subYear()->toDateString())->orderBy('date')->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'date' => ['required', 'date', Rule::unique('holidays')->where('hotel_id', $request->user()->hotel_id)->where('name', $request->input('name'))->ignore($record?->getKey())],
            'kind' => ['nullable', Rule::in(Holiday::KINDS)],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return ['date.unique' => 'This holiday is already on the list for that day.'];
    }

    /** Add the Ethiopian public holidays of the next 3 years (only the ones not on the list yet). */
    public function preset(Request $request, HotelSettings $settings): JsonResponse
    {
        $lang = (string) $settings->get('language', 'en');
        $added = 0;
        foreach (EthiopianHolidays::upcoming($lang) as $h) {
            $exists = Holiday::where('date', $h['date'])->where('name', $h['name'])->exists();
            if (! $exists) {
                Holiday::create($h + ['is_active' => true]);
                $added++;
            }
        }

        return response()->json(['added' => $added, 'data' => $this->query()->get()]);
    }
}
