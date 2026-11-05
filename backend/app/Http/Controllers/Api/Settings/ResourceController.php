<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * List / create / update / delete for a simple hotel-owned settings list
 * (taxes, payment methods, …). The tenant scope keeps it to the current hotel.
 */
abstract class ResourceController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return array<string, mixed> validation rules */
    abstract protected function rules(Request $request, ?Model $record): array;

    protected function query(): Builder
    {
        return ($this->model())::query()->orderBy('id');
    }

    /** Throw a ValidationException to refuse a delete. */
    protected function beforeDelete(Model $record): void {}

    /** @param array<string, mixed> $data */
    protected function saved(Model $record, array $data): void {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->query()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request, null));
        $record = ($this->model())::create($data);
        $this->saved($record, $data);

        return response()->json(['data' => $record->fresh()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $record = $this->find($id);
        $data = $request->validate($this->rules($request, $record));
        $record->update($data);
        $this->saved($record, $data);

        return response()->json(['data' => $record->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $record = $this->find($id);
        $this->beforeDelete($record);
        $record->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    protected function find(string $id): Model
    {
        return ($this->model())::findOrFail($id); // tenant-scoped: other hotels → 404
    }

    protected function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
