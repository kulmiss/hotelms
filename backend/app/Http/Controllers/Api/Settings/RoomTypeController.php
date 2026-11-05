<?php

namespace App\Http\Controllers\Api\Settings;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Room types per branch. Branch Managers only manage their own branches. */
class RoomTypeController extends ResourceController
{
    protected function model(): string
    {
        return RoomType::class;
    }

    protected function query(): Builder
    {
        $branches = request()->user()->accessibleBranches()->pluck('id');

        return RoomType::query()->with('branch:id,name')->withCount('rooms')
            ->whereIn('branch_id', $branches)
            ->when(request('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->orderBy('branch_id')->orderBy('nightly_price');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $branches = $request->user()->accessibleBranches()->pluck('id')->all();

        return [
            'branch_id' => ['required', 'integer', Rule::in($branches)],
            'name' => ['required', 'string', 'max:60', Rule::unique('room_types', 'name')->where('branch_id', $request->input('branch_id'))->whereNull('deleted_at')->ignore($record)],
            'description' => ['nullable', 'string', 'max:200'],
            'base_capacity' => ['required', 'integer', 'between:1,20'],
            'max_capacity' => ['required', 'integer', 'between:1,20', 'gte:base_capacity'],
            'nightly_price' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'day_use_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'hourly_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:40'],
        ];
    }

    protected function find(string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    protected function beforeDelete(Model $record): void
    {
        if (Room::where('room_type_id', $record->getKey())->exists()) {
            $this->refuse('room_type', 'Move or delete the rooms of this type first.');
        }
    }
}
