<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A rate-limit key for public guest forms that keeps no network address (owner, 14 Nov 2026):
 * a keyed hash, changed every day, only in the short-lived limit counter – never in the database or logs.
 */
class GuestThrottleKey
{
    public static function for(string $form, Request $request): string
    {
        return $form.'|'.hash_hmac('sha256', (string) $request->ip().'|'.now()->toDateString(), (string) config('app.key'));
    }
}
