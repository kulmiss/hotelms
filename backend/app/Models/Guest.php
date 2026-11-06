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

    protected $appends = ['full_name', 'details_missing'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'is_blacklisted' => 'boolean', 'id_number' => 'encrypted', 'notes' => 'encrypted'];
    }

    protected $hidden = ['id_number_hash'];

    /** "ET9494086" → "ET•••4086": enough to tell guests apart without showing the whole ID. */
    public static function maskId(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return $number;
        }
        $prefix = preg_match('/^[A-Za-z]+/', $number, $m) ? $m[0] : '';

        return mb_strlen($number) <= 4 ? '••••' : $prefix.'•••'.mb_substr($number, -4);
    }

    protected static function booted(): void
    {
        // Keep the search hash in step with the (encrypted) ID number.
        static::saving(function (Guest $g) {
            if ($g->isDirty('id_number')) {
                $g->id_number_hash = self::idHash($g->id_number);
            }
        });
    }

    /** Keyed hash of an ID number (spaces ignored, any case), so encrypted IDs can still be found exactly. */
    public static function idHash(?string $idNumber): ?string
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', (string) $idNumber));

        return $clean === '' ? null : hash_hmac('sha256', $clean, (string) config('app.key'));
    }

    /** ID numbers and notes never go into the activity log in clear (or as ciphertext). */
    protected function activityAttributes(array $attributes): array
    {
        $out = array_diff_key($attributes, array_flip(['updated_at', 'created_at', 'id_number_hash']));
        foreach (['id_number', 'notes'] as $secret) {
            if (array_key_exists($secret, $out)) {
                $out[$secret] = $out[$secret] === null ? null : '••••';
            }
        }

        return $out;
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

    /** A guest checked in quickly (name and phone only): the ID or nationality is still to be filled in. */
    public function getDetailsMissingAttribute(): ?bool
    {
        // Unknown when the guest was loaded without these columns (e.g. only the name).
        if (! array_key_exists('id_number', $this->attributes) || ! array_key_exists('nationality', $this->attributes)) {
            return null;
        }

        return blank($this->attributes['id_number']) || blank($this->attributes['nationality']);
    }

    public function scopeDetailsMissing(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('id_number')->orWhere('id_number', '')->orWhereNull('nationality')->orWhere('nationality', ''));
    }

    /** Search by name, phone, email, or exact ID number. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $digits = preg_replace('/\D+/', '', $term);

        return $q->where(function (Builder $w) use ($like, $digits, $term) {
            $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) like ?", [$like])
                ->orWhere('email', 'like', $like)->orWhere('id_number_hash', self::idHash($term));
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
