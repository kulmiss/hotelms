<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\GuestType;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Services\PriceCalculator;
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

    protected function query(): Builder
    {
        return RateRule::query()->orderByDesc('priority')->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'rule_type' => ['required', Rule::in(['weekend', 'season', 'guest_type'])],
            'room_type_id' => ['nullable', 'integer', Rule::in(RoomType::pluck('id')->all())],
            'start_date' => ['nullable', 'date', Rule::requiredIf($request->input('rule_type') === 'season')],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', Rule::requiredIf($request->input('rule_type') === 'season')],
            'days_of_week' => ['nullable', 'array', Rule::requiredIf($request->input('rule_type') === 'weekend')],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'guest_type_id' => ['nullable', 'integer', Rule::in(GuestType::pluck('id')->all()), Rule::requiredIf($request->input('rule_type') === 'guest_type')],
            'adjustment' => ['required', Rule::in(['fixed_price', 'percent', 'amount'])],
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
        $type = RoomType::findOrFail($data['room_type_id']);

        return response()->json(['data' => $prices->stay($type, Carbon::parse($data['from']), Carbon::parse($data['to']), $data['guest_type_id'] ?? null)]);
    }
}
