<?php

namespace App\Support\Tenancy;

use App\Models\Hotel;
use Closure;

/**
 * Holds the hotel the current request (or job) works for.
 *
 * Hotel-owned models are scoped to this hotel. When no hotel is set,
 * they return nothing (fail closed) unless the scope is bypassed on purpose.
 */
class TenantContext
{
    private ?int $hotelId = null;

    private bool $bypassed = false;

    public function set(Hotel|int|null $hotel): void
    {
        $this->hotelId = $hotel instanceof Hotel ? $hotel->getKey() : $hotel;
    }

    public function hotelId(): ?int
    {
        return $this->hotelId;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * Run code as one hotel (jobs, seeders, Super Admin tools).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Hotel|int $hotel, Closure $callback): mixed
    {
        [$previousId, $previousBypass] = [$this->hotelId, $this->bypassed];
        $this->set($hotel);
        $this->bypassed = false;

        try {
            return $callback();
        } finally {
            [$this->hotelId, $this->bypassed] = [$previousId, $previousBypass];
        }
    }

    /**
     * Run code across all hotels (Super Admin platform views only).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function acrossAllHotels(Closure $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }
}
