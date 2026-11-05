<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Hotel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the activity log. Never stores passwords or tokens.
 */
class ActivityLogger
{
    private const HIDDEN = ['password', 'remember_token', 'attendance_pin', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function __construct(private TenantContext $tenant) {}

    /** @param  array<string, mixed>  $properties */
    public function log(string $event, string $description, ?Model $subject = null, array $properties = [], ?int $hotelId = null): ActivityLog
    {
        $hotelId ??= $subject instanceof Hotel
            ? $subject->getKey()
            : ($subject?->getAttribute('hotel_id') ?? $this->tenant->hotelId());

        // Platform entries (no hotel) must not be blocked by the tenant scope.
        return $this->tenant->acrossAllHotels(fn () => ActivityLog::create([
            'hotel_id' => $hotelId,
            'user_id' => auth()->id(),
            'event' => $event,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ? $this->clean($properties) : null,
            'ip_address' => request()?->ip(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function clean(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (in_array($key, self::HIDDEN, true)) {
                unset($properties[$key]);
            } elseif (is_array($value)) {
                $properties[$key] = $this->clean($value);
            }
        }

        return $properties;
    }
}
