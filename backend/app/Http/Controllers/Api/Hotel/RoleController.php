<?php

namespace App\Http\Controllers\Api\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->withCount('users')->orderByDesc('is_locked')->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['data' => $roles->map(fn (Role $r) => $this->present($r))]);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $this->present($role->loadCount('users'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guardGrant($request->user(), $data['permissions']);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'max_discount_percent' => $data['max_discount_percent'],
            ]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        return response()->json(['data' => $this->present($role->loadCount('users'))], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->guardEditable($request->user(), $role);
        $data = $this->validated($request, $role);
        $this->guardGrant($request->user(), $data['permissions']);

        DB::transaction(function () use ($role, $data) {
            $before = $role->permissionKeys();
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'max_discount_percent' => $data['max_discount_percent'],
            ]);
            $role->syncPermissions($data['permissions']);

            $added = array_values(array_diff($data['permissions'], $before));
            $removed = array_values(array_diff($before, $data['permissions']));
            if ($added || $removed) {
                app(ActivityLogger::class)->log('role.permissions', "Permissions changed: {$role->name}", $role, compact('added', 'removed'));
            }
        });

        return response()->json(['data' => $this->present($role->fresh()->loadCount('users'))]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->guardEditable($request->user(), $role);

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Move the staff with this role to another role first.']);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Role $role = null): array
    {
        $hotelId = $request->user()->hotel_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('hotel_id', $hotelId)->ignore($role)],
            'description' => ['nullable', 'string', 'max:200'],
            'max_discount_percent' => ['required', 'decimal:0,2', 'between:0,100'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);
    }

    private function guardEditable(User $actor, Role $role): void
    {
        if ($role->is_locked) {
            throw ValidationException::withMessages(['role' => 'The Hotel Admin role cannot be changed.']);
        }
        if ($actor->role_id === $role->id) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }
    }

    /** @param  list<string>  $permissions */
    private function guardGrant(User $actor, array $permissions): void
    {
        $missing = array_diff($permissions, $actor->permissionKeys());
        if ($missing !== []) {
            throw ValidationException::withMessages(['permissions' => 'You can only give permissions you have yourself.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'is_default' => $role->is_default,
            'is_locked' => $role->is_locked,
            'max_discount_percent' => $role->max_discount_percent,
            'permissions' => $role->permissionKeys(),
            'users_count' => $role->users_count ?? null,
        ];
    }
}
