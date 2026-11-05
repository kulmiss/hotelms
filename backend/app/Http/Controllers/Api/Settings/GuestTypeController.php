<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\GuestType;
use App\Models\RateRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuestTypeController extends ResourceController
{
    protected function model(): string
    {
        return GuestType::class;
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return ['name' => ['required', 'string', 'max:60', Rule::unique('guest_types', 'name')->where('hotel_id', $request->user()->hotel_id)->ignore($record)]];
    }

    protected function beforeDelete(Model $record): void
    {
        if (RateRule::where('guest_type_id', $record->getKey())->exists()) {
            $this->refuse('guest_type', 'Delete the rate rules for this guest type first.');
        }
    }
}
