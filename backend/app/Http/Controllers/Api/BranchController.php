<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    /**
     * Branches of the signed-in user's hotel (tenant-scoped).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Branch::query()->orderBy('name')->get(['id', 'name', 'code', 'city', 'status']),
        ]);
    }
}
