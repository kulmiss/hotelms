<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    public function __construct(private Impersonation $impersonation) {}

    /** Super Admin opens a hotel as one of its users (the Hotel Admin by default). */
    public function start(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $target = User::where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->whereKey($id), function ($q) {
                $q->whereHas('role', fn ($r) => $r->withoutGlobalScope('hotel')->where('key', 'hotel_admin'));
            })
            ->orderBy('id')
            ->first();

        if (! $target) {
            throw ValidationException::withMessages(['user_id' => 'This hotel has no active user to sign in as.']);
        }

        $this->impersonation->start($request, $request->user(), $hotel, $target, $data['reason']);

        return response()->json(['message' => 'Signed in to the hotel.', 'as' => ['id' => $target->id, 'name' => $target->name]]);
    }
}
