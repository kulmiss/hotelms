<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide settings edited by the Super Admin (e.g. default trial length).
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    public const DEFAULTS = [
        'default_trial_days' => 30,
        'default_trial_plan_id' => null,
        'grace_days' => 3,
    ];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function read(string $key): mixed
    {
        $row = static::where('key', $key)->first();

        return $row ? $row->value : (self::DEFAULTS[$key] ?? null);
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return array<string, mixed> */
    public static function allValues(): array
    {
        $saved = static::pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }
}
