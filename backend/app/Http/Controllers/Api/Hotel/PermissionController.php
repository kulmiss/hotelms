<?php

namespace App\Http\Controllers\Api\Hotel;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /** The permission catalogue, grouped, for the role editor. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => collect(Permissions::groups())->map(fn (array $perms, string $group) => [
                'group' => $group,
                'permissions' => array_map(fn ($p) => ['key' => $p, 'module' => Permissions::moduleOf($p)], $perms),
            ])->values(),
        ]);
    }
}
