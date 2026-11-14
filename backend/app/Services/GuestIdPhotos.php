<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\GuestIdPhoto;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Support\SafeImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guest ID / passport photos (optional, per hotel in Booking rules): allowed, required at check-in,
 * and how long they are kept. Files are encrypted on disk and only shown to staff who may see guests.
 */
class GuestIdPhotos
{
    public function __construct(private HotelSettings $settings, private ActivityLogger $log) {}

    /** @return array{enabled: bool, required: bool, keep_months: int} */
    public function rules(): array
    {
        $s = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);

        return ['enabled' => (bool) $s['id_photo_enabled'], 'required' => (bool) $s['id_photo_enabled'] && (bool) $s['id_photo_required'], 'keep_months' => (int) $s['id_photo_keep_months']];
    }

    public function save(Guest $guest, UploadedFile $file, User $user): GuestIdPhoto
    {
        if (! $this->rules()['enabled']) {
            throw ValidationException::withMessages(['file' => 'ID photos are turned off for your hotel (Settings → Booking rules).']);
        }
        [$bytes, $mime] = SafeImage::clean($file); // size limit, hidden data and metadata (GPS) removed (SECURITY_REPORT L4)
        $old = (int) GuestIdPhoto::where('guest_id', $guest->id)->value('size'); // a new photo replaces the old one
        app(StorageQuota::class)->check($guest->hotel, max(0, strlen($bytes) - $old));
        $path = "guest-ids/{$guest->hotel_id}/".Str::uuid().'.bin';
        Storage::disk('local')->put($path, Crypt::encrypt($bytes, false));

        return DB::transaction(function () use ($guest, $bytes, $mime, $user, $path) {
            $old = GuestIdPhoto::where('guest_id', $guest->id)->first();
            if ($old) {
                Storage::disk('local')->delete($old->path);
                $old->delete();
            }
            $photo = GuestIdPhoto::create(['guest_id' => $guest->id, 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'uploaded_by' => $user->id]);
            $this->log->log('guest.id_photo', "ID photo added for {$guest->full_name}", $guest);

            return $photo;
        });
    }

    /** The decrypted file. */
    public function contents(GuestIdPhoto $photo): ?string
    {
        $raw = Storage::disk('local')->get($photo->path);

        return $raw === null ? null : Crypt::decrypt($raw, false);
    }

    public function remove(Guest $guest): void
    {
        $photo = GuestIdPhoto::where('guest_id', $guest->id)->first();
        if ($photo) {
            Storage::disk('local')->delete($photo->path);
            $photo->delete();
            $this->log->log('guest.id_photo', "ID photo removed for {$guest->full_name}", $guest);
        }
    }

    /** At check-in: the hotel requires a photo and this guest has none. */
    public function missingFor(Reservation $reservation): bool
    {
        return $this->rules()['required'] && ! GuestIdPhoto::where('guest_id', $reservation->guest_id)->exists();
    }

    /** Photos older than the hotel keeps them: removed some months after the guest's last stay (0 = keep). */
    public function cleanUp(Hotel $hotel): int
    {
        $months = $this->rules()['keep_months'];
        if ($months <= 0) {
            return 0;
        }
        $limit = now()->subMonths($months);
        $n = 0;
        GuestIdPhoto::with('guest')->where('created_at', '<', $limit)->get()->each(function (GuestIdPhoto $p) use ($limit, &$n) {
            $last = Reservation::where('guest_id', $p->guest_id)->max('check_out_at');
            if ($last === null || Carbon::parse($last)->lt($limit)) {
                Storage::disk('local')->delete($p->path);
                $p->delete();
                $n++;
            }
        });

        return $n;
    }
}
