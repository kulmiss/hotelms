<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\CancellationPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CancellationPolicyController extends ResourceController
{
    protected function model(): string
    {
        return CancellationPolicy::class;
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'free_until_hours' => ['nullable', 'integer', 'between:0,8760'],
            'penalty_type' => ['required', Rule::in(['nights', 'percent', 'fixed'])],
            'penalty_value' => ['required', 'decimal:0,2', 'min:0', $request->input('penalty_type') === 'percent' ? 'max:100' : 'max:9999999'],
            'applies_to_no_show' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    /** Exactly one default policy. */
    protected function saved(Model $record, array $data): void
    {
        if (! empty($data['is_default'])) {
            CancellationPolicy::whereKeyNot($record->getKey())->update(['is_default' => false]);
        } elseif (CancellationPolicy::where('is_default', true)->doesntExist()) {
            $record->update(['is_default' => true]);
        }
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->is_default) {
            $this->refuse('policy', 'Choose another default policy before deleting this one.');
        }
    }
}
