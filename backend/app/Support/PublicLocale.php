<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The language of the public pages (website, legal pages, booking page): English or Amharic.
 * No cookie and no session (owner, 14 Nov 2026), so the choice travels in the link: ?lang=am.
 * Without ?lang the website is English; a booking page uses the hotel's own default language.
 */
class PublicLocale
{
    public const LANGUAGES = ['en', 'am'];

    public static function apply(Request $request, string $default = 'en'): string
    {
        $asked = $request->query('lang');
        $lang = is_string($asked) && in_array($asked, self::LANGUAGES, true) ? $asked
            : (in_array($default, self::LANGUAGES, true) ? $default : 'en');
        App::setLocale($lang);

        return $lang;
    }

    /** A link to another public page in the current language. */
    public static function url(string $path): string
    {
        if (App::getLocale() === 'en') {
            return $path;
        }
        [$base, $hash] = array_pad(explode('#', $path, 2), 2, null);

        return $base.(str_contains($base, '?') ? '&' : '?').'lang='.App::getLocale().($hash !== null ? '#'.$hash : '');
    }

    /** The same page in another language (for the switch and hreflang links). */
    public static function switchTo(string $path, string $lang): string
    {
        return $path.(str_contains($path, '?') ? '&' : '?').'lang='.$lang;
    }
}
