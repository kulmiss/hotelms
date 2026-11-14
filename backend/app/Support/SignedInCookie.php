<?php

namespace App\Support;

use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Is this visitor signed in? Read from the sign-in cookie they already have, without starting a session,
 * so public pages never set a cookie (owner, 14 Nov 2026: no cookies or tracking for visitors).
 */
class SignedInCookie
{
    public static function check(Request $request): bool
    {
        $raw = $request->cookies->get((string) config('session.cookie'));
        if (! is_string($raw) || $raw === '') {
            return false;
        }
        try {
            $id = CookieValuePrefix::remove(Crypt::decrypt($raw, false));
            if (! is_string($id) || strlen($id) !== 40 || ! ctype_alnum($id)) {
                return false;
            }
            $data = app('session')->driver()->getHandler()->read($id);
            if (! is_string($data) || $data === '') {
                return false;
            }
            $values = config('session.serialization') === 'json' ? json_decode($data, true) : @unserialize($data, ['allowed_classes' => false]);

            return is_array($values) && ! empty($values['login_web_'.sha1(SessionGuard::class)]);
        } catch (Throwable) {
            return false;
        }
    }
}
