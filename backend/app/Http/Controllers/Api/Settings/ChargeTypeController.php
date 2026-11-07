<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\ChargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ChargeTypeController extends ResourceController
{
    protected function model(): string
    {
        return ChargeType::class;
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'default_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'is_quick' => ['boolean'], // a one-tap button on the room board and "Charge a room"
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
        ];
    }
}
