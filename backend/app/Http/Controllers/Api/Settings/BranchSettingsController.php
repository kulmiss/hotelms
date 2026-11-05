<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Branch;
use App\Models\CashierSession;
use App\Models\Invoice;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Branches (Hotel Admin). The plan decides how many branches a hotel may have. */
class BranchSettingsController extends ResourceController
{
    protected function model(): string
    {
        return Branch::class;
    }

    protected function query(): Builder
    {
        return Branch::query()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'alpha_num', 'max:6', Rule::unique('branches', 'code')->where('hotel_id', $request->user()->hotel_id)->ignore($record)],
            'city' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'check_in_time' => ['required', 'date_format:H:i'],
            'check_out_time' => ['required', 'date_format:H:i'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $limit = $request->user()->hotel->effectivePlan()?->max_branches;
        if ($limit !== null && Branch::count() >= $limit) {
            $this->refuse('name', "Your plan allows {$limit} branch(es). Upgrade the plan to add more.");
        }

        return parent::store($request);
    }

    protected function beforeDelete(Model $record): void
    {
        if (Branch::count() <= 1) {
            $this->refuse('branch', 'A hotel needs at least one branch.');
        }
        if (Reservation::where('branch_id', $record->getKey())->whereIn('status', Reservation::ACTIVE)->exists()) {
            $this->refuse('branch', 'This branch has guests or bookings. Finish or cancel them first.');
        }
        if (Invoice::where('branch_id', $record->getKey())->where('status', '!=', 'void')->where('balance', '>', 0)->exists()) {
            $this->refuse('branch', 'This branch has unpaid bills. Settle them first.');
        }
        if (CashierSession::where('branch_id', $record->getKey())->whereNull('closed_at')->exists()) {
            $this->refuse('branch', 'A cashier shift is still open in this branch.');
        }
    }
}
