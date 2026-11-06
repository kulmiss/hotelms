<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\ActivityLogger;
use App\Services\PlatformPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Super Admin → Team and security (area 9): HotelMS team members and what each role may do. Owner only. */
class TeamController extends Controller
{
    public function __construct(private ActivityLogger $log, private PlatformPermissions $permissions) {}

    public function index(): JsonResponse
    {
        $members = User::where('is_super_admin', true)->orderByRaw("platform_role = 'owner' desc")->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'platform_role', 'status', 'last_login_at', 'two_factor_confirmed_at', 'must_change_password']);

        return response()->json([
            'data' => $members->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role' => $u->platform_role, 'status' => $u->status,
                'two_factor' => $u->two_factor_confirmed_at !== null, 'last_login_at' => $u->last_login_at?->toIso8601String(), 'invited' => (bool) $u->must_change_password,
            ])->values(),
            'roles' => config('hotelms.platform_roles'),
            'permissions' => $this->permissions->map(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(config('hotelms.platform_roles'))],
        ]);
        $password = Str::password(12, symbols: false);
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null]);
        $user->forceFill(['password' => $password, 'is_super_admin' => true, 'platform_role' => $data['role'], 'status' => 'active', 'must_change_password' => true, 'hotel_id' => null])->save();
        $this->log->log('team.added', "Added {$user->name} to the HotelMS team ({$data['role']})", $user);

        return response()->json(['data' => ['id' => $user->id], 'temporary_password' => $password], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $member = User::where('is_super_admin', true)->findOrFail($id);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(config('hotelms.platform_roles'))],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]);
        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role or turn yourself off.']);
        }
        $losesOwner = $member->platform_role === 'owner' && (($data['role'] ?? 'owner') !== 'owner' || ($data['status'] ?? 'active') !== 'active');
        if ($losesOwner && User::where('is_super_admin', true)->where('platform_role', 'owner')->where('status', 'active')->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'HotelMS needs at least one active owner.']);
        }

        DB::transaction(function () use ($member, $data) {
            $member->forceFill(array_filter(['platform_role' => $data['role'] ?? null, 'status' => $data['status'] ?? null]))->save();
            if (($data['status'] ?? null) === 'disabled') {
                // Signed out everywhere at once.
                $member->tokens()->delete();
                DB::table('sessions')->where('user_id', $member->id)->delete();
                UserDevice::where('user_id', $member->id)->where('status', 'active')->update(['status' => 'signed_out']);
            }
        });
        $this->log->log('team.changed', "Changed {$member->name}: ".collect($data)->map(fn ($v, $k) => "{$k} {$v}")->implode(', '), $member);

        return response()->json(['message' => 'Saved.']);
    }

    /** The role × permission grid (the owner column is locked). */
    public function savePermissions(Request $request): JsonResponse
    {
        // Permission names contain dots, so the grid is checked here, not with "permissions.*" rules.
        $request->validate(['permissions' => ['required', 'array']]);
        $grid = (array) $request->input('permissions');
        foreach ($grid as $roles) {
            if (! is_array($roles) || array_diff($roles, $this->permissions->roles()) !== []) {
                throw ValidationException::withMessages(['permissions' => 'Choose only HotelMS team roles.']);
            }
        }
        $this->permissions->save($grid);
        $this->log->log('team.permissions', 'Changed what HotelMS team roles may do', null, ['permissions' => $this->permissions->map()]);

        return response()->json(['permissions' => $this->permissions->map()]);
    }
}
