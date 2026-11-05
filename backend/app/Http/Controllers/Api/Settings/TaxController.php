<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxController extends ResourceController
{
    protected function model(): string
    {
        return Tax::class;
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'decimal:0,2', 'between:0,100'],
            'applies_to' => ['required', Rule::in(['rooms', 'extras', 'all'])],
            'is_inclusive' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
