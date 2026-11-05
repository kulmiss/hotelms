<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends ResourceController
{
    protected function model(): string
    {
        return PaymentMethod::class;
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(['cash', 'mobile_money', 'bank_transfer'])],
            'is_active' => ['boolean'],
        ];
    }

    protected function beforeDelete(Model $record): void
    {
        if (PaymentMethod::where('is_active', true)->whereKeyNot($record->getKey())->doesntExist()) {
            $this->refuse('payment_method', 'Keep at least one payment method.');
        }
    }
}
