<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Guests are shared by all branches of a hotel. */
#[Fillable(['hotel_id', 'guest_type_id', 'first_name', 'last_name', 'phone', 'email', 'nationality', 'id_type', 'id_number', 'gender', 'date_of_birth', 'notes', 'is_blacklisted', 'blacklist_reason'])]
class Guest extends Model
{
    use BelongsToHotel, LogsActivity, SoftDeletes;

    public const ID_TYPES = ['national_id', 'passport', 'kebele_id', 'driving_licence'];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'is_blacklisted' => 'boolean'];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<GuestType, $this> */
    public function guestType(): BelongsTo
    {
        return $this->belongsTo(GuestType::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** Search by name, phone, email or ID number. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $digits = preg_replace('/\D+/', '', $term);

        return $q->where(function (Builder $w) use ($like, $digits) {
            $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) like ?", [$like])
                ->orWhere('email', 'like', $like)->orWhere('id_number', 'like', $like);
            if (strlen((string) $digits) >= 4) {
                $w->orWhere('phone', 'like', '%'.substr((string) $digits, -9).'%');
            }
        });
    }

    public function activityName(): string
    {
        return $this->full_name;
    }
}
