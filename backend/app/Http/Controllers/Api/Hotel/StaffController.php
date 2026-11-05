<?php

namespace App\Http\Controllers\Api\Hotel;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvite;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Security\TwoFactor;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Hotel staff accounts. Rules (permissions.md):
 * - Branch Managers only see and manage staff of their own branches,
 *   cannot touch Hotel Admins, and cannot give roles with permissions they lack.
 * - Nobody changes their own role or disables themselves.
 * - A hotel always keeps at least one active Hotel Admin.
 */
class StaffController extends Controller
{
    public function __construct(private ActivityLogger $log) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $staff = $this->visibleStaff($actor)
            ->with(['role', 'branches'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $staff->map(fn (User $u) => $this->present($u))]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->guardVisible($request->user(), $user);

        return response()->json(['data' => $this->present($user->load(['role', 'branches']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $this->validated($request);
        $role = $this->allowedRole($actor, (int) $data['role_id']);
        $branchIds = $this->allowedBranches($actor, $data['branch_ids'] ?? [], $role);
        $password = Str::password(12, symbols: false);

        $user = DB::transaction(function () use ($actor, $data, $role, $branchIds, $password) {
            $user = User::create([
                'hotel_id' => $actor->hotel_id,
                'role_id' => $role->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'status' => 'active',
                'must_change_password' => true,
            ]);
            $user->branches()->sync($branchIds);

            return $user;
        });

        $emailSent = $this->email($user, $password, false, $data['send_email'] ?? true);

        return response()->json([
            'data' => $this->present($user->load(['role', 'branches'])),
            'temporary_password' => $password,
            'email_sent' => $emailSent,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->guardManageable($actor, $user);
        $data = $this->validated($request, $user);

        $roleId = (int) $data['role_id'];
        if ($roleId !== $user->role_id) {
            if ($actor->is($user)) {
                throw ValidationException::withMessages(['role_id' => 'You cannot change your own role.']);
            }
            $this->guardKeepsAnAdmin($user);
        }
        $role = $this->allowedRole($actor, $roleId, $user);
        $branchIds = $this->allowedBranches($actor, $data['branch_ids'] ?? [], $role);

        DB::transaction(function () use ($user, $data, $role, $branchIds) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role_id' => $role->id,
            ]);
            $before = $user->branches()->pluck('branches.id')->all();
            $user->branches()->sync($branchIds);
            if (array_diff($before, $branchIds) || array_diff($branchIds, $before)) {
                $this->log->log('staff.branches', "Branches changed: {$user->name}", $user, ['old' => $before, 'new' => $branchIds]);
            }
        });

        return response()->json(['data' => $this->present($user->fresh()->load(['role', 'branches']))]);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->guardManageable($actor, $user);
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot disable your own account.']);
        }
        $this->guardKeepsAnAdmin($user);

        $user->forceFill(['status' => 'disabled', 'remember_token' => Str::random(60)])->save();
        $this->signOutEverywhere($user);
        $this->log->log('staff.disabled', "Staff disabled: {$user->name}", $user);

        return response()->json(['data' => $this->present($user->load(['role', 'branches']))]);
    }

    public function enable(Request $request, User $user): JsonResponse
    {
        $this->guardManageable($request->user(), $user);

        $user->forceFill(['status' => 'active'])->save();
        $this->log->log('staff.enabled', "Staff enabled: {$user->name}", $user);

        return response()->json(['data' => $this->present($user->load(['role', 'branches']))]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->guardManageable($actor, $user);
        $sendEmail = $request->boolean('send_email', true);
        $password = Str::password(12, symbols: false);

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();
        $this->signOutEverywhere($user);
        app(TwoFactor::class)->disable($user); // a lost phone: set two-step sign-in up again
        $this->log->log('staff.password_reset', "Password reset: {$user->name}", $user);

        return response()->json([
            'temporary_password' => $password,
            'email_sent' => $this->email($user, $password, true, $sendEmail),
        ]);
    }

    // ---------------------------------------------------------------- rules

    /** Staff the actor may see: everyone for Hotel Admins, own branches for others. */
    private function visibleStaff(User $actor)
    {
        $query = User::query()->where('hotel_id', $actor->hotel_id);

        if (! $actor->isHotelAdmin()) {
            $branchIds = $actor->accessibleBranches()->pluck('id');
            $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds));
        }

        return $query;
    }

    private function guardVisible(User $actor, User $user): void
    {
        abort_unless($this->visibleStaff($actor)->whereKey($user->id)->exists() || $actor->is($user), 404);
    }

    private function guardManageable(User $actor, User $user): void
    {
        $this->guardVisible($actor, $user);

        if ($user->isHotelAdmin() && ! $actor->isHotelAdmin()) {
            abort(response()->json(['message' => 'Only a Hotel Admin can change another Hotel Admin.', 'code' => 'forbidden'], 403));
        }
    }

    private function guardKeepsAnAdmin(User $user): void
    {
        if (! $user->isHotelAdmin()) {
            return;
        }

        $otherAdmins = User::where('hotel_id', $user->hotel_id)
            ->where('role_id', $user->role_id)
            ->where('status', 'active')
            ->whereKeyNot($user->id)
            ->exists();

        if (! $otherAdmins) {
            throw ValidationException::withMessages(['user' => 'A hotel must always keep at least one active Hotel Admin.']);
        }
    }

    private function allowedRole(User $actor, int $roleId, ?User $editing = null): Role
    {
        $role = Role::find($roleId); // tenant-scoped: only this hotel's roles
        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role.']);
        }

        if ($editing && $editing->role_id === $role->id) {
            return $role; // unchanged
        }

        if ($role->isHotelAdmin() && ! $actor->isHotelAdmin()) {
            throw ValidationException::withMessages(['role_id' => 'Only a Hotel Admin can make someone a Hotel Admin.']);
        }

        if (array_diff($role->permissionKeys(), $actor->permissionKeys())) {
            throw ValidationException::withMessages(['role_id' => 'You can only give a role with permissions you have yourself.']);
        }

        return $role;
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    private function allowedBranches(User $actor, array $branchIds, Role $role): array
    {
        $own = $actor->accessibleBranches()->pluck('id')->all();
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));

        if (array_diff($branchIds, $own)) {
            throw ValidationException::withMessages(['branch_ids' => 'You can only choose branches you work in.']);
        }

        if ($branchIds === [] && ! $role->isHotelAdmin()) {
            throw ValidationException::withMessages(['branch_ids' => 'Choose at least one branch.']);
        }

        return $branchIds;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'integer'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer'],
            'send_email' => ['sometimes', 'boolean'],
        ]);
    }

    private function signOutEverywhere(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete(); // database sessions
        UserDevice::where('user_id', $user->id)->where('status', 'active')->update(['status' => 'signed_out']); // any session driver
    }

    private function email(User $user, string $password, bool $isReset, bool $send): bool
    {
        if (! $send || $user->hotel?->is_demo) {
            return false; // the demo hotel never sends real emails
        }

        try {
            Mail::to($user->email)->send(new StaffInvite($user, $user->hotel, $password, $isReset));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'role' => $user->role ? ['id' => $user->role->id, 'name' => $user->role->name, 'is_locked' => $user->role->is_locked] : null,
            'branches' => $user->branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values(),
        ];
    }
}
