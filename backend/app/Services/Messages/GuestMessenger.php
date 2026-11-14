<?php

namespace App\Services\Messages;

use App\Mail\ReportMail;
use App\Models\BookingRequest;
use App\Models\Guest;
use App\Models\GuestMessage;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Feedback\FeedbackLinks;
use App\Services\HotelSettings;
use App\Services\Switches;
use App\Support\EthiopianDate;
use App\Support\HotelClock;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Messages to guests (owner, 14 Nov 2026): automatic by email (every plan) and SMS (paid plans, the hotel's own SMS
 * account), or by a tap: WhatsApp and the phone's SMS app open with the text ready (free, every plan).
 * Rules: nothing to a guest who said no (opt-out); the thank-you only with a ticked yes; each automatic message once;
 * nothing from the demo hotel or a hotel that is not confirmed yet. Every message goes to the booking's message log.
 */
class GuestMessenger
{
    public const SETTINGS = 'guest_messages';

    public const INFO = 'guest_info';

    public function __construct(private MessageTemplates $templates, private HotelSettings $settings, private SmsGateway $sms, private Switches $switches) {}

    /** Which messages go out by themselves, by which channel. @return array<string, array{email: bool, sms: bool}> */
    public function auto(): array
    {
        $saved = (array) $this->settings->get(self::SETTINGS, []);
        $out = [];
        foreach (MessageTemplates::keys() as $key) {
            $out[$key] = ['email' => (bool) ($saved[$key]['email'] ?? true), 'sms' => (bool) ($saved[$key]['sms'] ?? false)];
        }

        return $out;
    }

    /** @return array{wifi_name: ?string, wifi_password: ?string, breakfast: ?string, whatsapp: ?string, menu_url: ?string} */
    public function info(): array
    {
        $i = (array) $this->settings->get(self::INFO, []);

        return ['wifi_name' => $i['wifi_name'] ?? null, 'wifi_password' => $i['wifi_password'] ?? null, 'breakfast' => $i['breakfast'] ?? null, 'whatsapp' => $i['whatsapp'] ?? null, 'menu_url' => $i['menu_url'] ?? null];
    }

    /** May this message go to this guest at all? */
    public function allowed(string $key, ?Guest $guest, bool $consent): bool
    {
        if ($guest?->messages_opt_out) {
            return false;
        }

        return config("guest_messages.messages.{$key}.consent") !== 'ticked' || $consent;
    }

    /** The automatic message of a booking (from the booking's events and the scheduler). Each one once per channel. */
    public function sendAuto(Hotel $hotel, Reservation $r, string $key): void
    {
        $guest = $r->guest;
        if (! $hotel->maySendMessages() || ! $this->allowed($key, $guest, (bool) $r->feedback_consent)) {
            return;
        }
        $lang = $this->language($guest);
        $auto = $this->auto()[$key] ?? ['email' => false, 'sms' => false];
        $msg = $this->templates->render($key, $lang, $this->vars($hotel, $r, $key));
        if ($auto['email'] && $guest?->email && ! $this->already($r, $key, 'email')) {
            $this->email($hotel, $r, null, $guest, $key, $lang, $guest->email, $msg, null);
        }
        if ($auto['sms'] && $guest?->phone && $this->sms->ready() && $this->switches->on($hotel, 'feature.auto_sms') && ! $this->already($r, $key, 'sms')) {
            $this->smsSend($r, null, $guest, $key, $lang, $guest->phone, $msg['body'], null);
        }
    }

    /** Messages about a booking-page request (no booking yet). */
    public function sendForRequest(Hotel $hotel, BookingRequest $req, string $key): void
    {
        if (! $hotel->maySendMessages()) {
            return;
        }
        $lang = in_array($req->language, MessageTemplates::LANGUAGES, true) ? $req->language : $this->hotelLanguage();
        $auto = $this->auto()[$key] ?? ['email' => false, 'sms' => false];
        $msg = $this->templates->render($key, $lang, $this->requestVars($hotel, $req));
        if ($auto['email'] && $req->email) {
            $this->email($hotel, null, $req, null, $key, $lang, $req->email, $msg, null);
        }
        if ($auto['sms'] && $req->phone && $this->sms->ready() && $this->switches->on($hotel, 'feature.auto_sms')) {
            $this->smsSend(null, $req, null, $key, $lang, $req->phone, $msg['body'], null);
        }
    }

    /**
     * Staff send a message of a booking now. Email and SMS go out; WhatsApp and the SMS app answer with the link to open
     * (the text is ready; the person taps Send on their phone).
     *
     * @return array{status: string, link: ?string, error: ?string, text: string}
     */
    public function sendNow(Hotel $hotel, Reservation $r, string $key, string $channel, User $by, ?string $lang = null): array
    {
        $guest = $r->guest;
        if (! $this->allowed($key, $guest, (bool) $r->feedback_consent)) {
            throw new RuntimeException($guest?->messages_opt_out ? 'This guest asked for no messages.' : 'The guest did not say yes to this message.');
        }
        $lang = in_array($lang, MessageTemplates::LANGUAGES, true) ? $lang : $this->language($guest);
        $msg = $this->templates->render($key, $lang, $this->vars($hotel, $r, $key));
        $text = $msg['body'];
        $phone = preg_replace('/\D+/', '', (string) $guest?->phone);

        return match ($channel) {
            'email' => $guest?->email ? $this->email($hotel, $r, null, $guest, $key, $lang, $guest->email, $msg, $by) + ['link' => null, 'text' => $text] : throw new RuntimeException('The guest has no email address.'),
            'sms' => $phone ? $this->smsSend($r, null, $guest, $key, $lang, (string) $guest->phone, $text, $by) + ['link' => null, 'text' => $text] : throw new RuntimeException('The guest has no phone number.'),
            'whatsapp' => $phone ? $this->opened($r, $guest, $key, 'whatsapp', $lang, (string) $guest->phone, $by) + ['link' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text), 'text' => $text] : throw new RuntimeException('The guest has no phone number.'),
            'sms_app' => $phone ? $this->opened($r, $guest, $key, 'sms_app', $lang, (string) $guest->phone, $by) + ['link' => 'sms:+'.ltrim($phone, '+').'?body='.rawurlencode($text), 'text' => $text] : throw new RuntimeException('The guest has no phone number.'),
            default => throw new RuntimeException('Choose how to send it.'),
        };
    }

    /** The text of a message for a booking, for the preview in the booking. */
    public function preview(Hotel $hotel, Reservation $r, string $key, ?string $lang = null): array
    {
        $lang = in_array($lang, MessageTemplates::LANGUAGES, true) ? $lang : $this->language($r->guest);

        return $this->templates->render($key, $lang, $this->vars($hotel, $r, $key)) + ['language' => $lang];
    }

    /** @return array<string, string|null> */
    public function vars(Hotel $hotel, Reservation $r, string $key = ''): array
    {
        $lang = $this->language($r->guest);
        $in = HotelClock::local($r->check_in_at, $hotel);
        $out = HotelClock::local($r->check_out_at, $hotel);
        $info = $this->info();
        $cur = (string) $r->currency;
        $balance = Money::sub((string) $r->total, (string) $r->paid_total);
        $branch = $r->branch;

        return [
            'guest_name' => $r->guest?->full_name, 'hotel_name' => $hotel->name, 'booking_code' => $r->code,
            'arrive' => $this->when($in, $lang, $r->stay_type !== 'nightly'), 'leave' => $this->when($out, $lang, $r->stay_type !== 'nightly'),
            'nights' => (string) max(1, (int) $in->copy()->startOfDay()->diffInDays($out->copy()->startOfDay())),
            'total' => "{$cur} ".number_format((float) $r->total, 2), 'paid' => "{$cur} ".number_format((float) $r->paid_total, 2),
            'balance' => Money::isPositive($balance) ? "{$cur} ".number_format((float) $balance, 2) : null,
            'check_in_time' => $in->format('H:i'),
            'hotel_phone' => $branch?->phone ?: $hotel->phone, 'address' => trim(($branch?->address ?: $hotel->address).' '.($branch?->city ?: $hotel->city)) ?: null,
            'wifi_name' => $info['wifi_name'], 'wifi_password' => $info['wifi_password'], 'breakfast' => $info['breakfast'],
            'whatsapp' => $info['whatsapp'], 'menu_link' => $info['menu_url'],
            'feedback_link' => $key === 'thank_you' ? $this->feedbackLink($r) : null,
        ];
    }

    /** The secure feedback link of the stay (only when the guest said yes). */
    public function feedbackLink(Reservation $r): ?string
    {
        return app(FeedbackLinks::class)->linkFor($r);
    }

    /** @return array<string, string|null> */
    private function requestVars(Hotel $hotel, BookingRequest $req): array
    {
        $lang = in_array($req->language, MessageTemplates::LANGUAGES, true) ? $req->language : $this->hotelLanguage();

        return [
            'guest_name' => $req->name, 'hotel_name' => $hotel->name, 'booking_code' => null,
            'arrive' => $this->when($req->check_in, $lang, false), 'leave' => $this->when($req->check_out, $lang, false),
            'hotel_phone' => $hotel->phone, 'address' => trim($hotel->address.' '.$hotel->city) ?: null, 'whatsapp' => $this->info()['whatsapp'],
        ];
    }

    /** "Sat 21 Nov 2026" (+ "12 Hidar 2019" when the hotel uses the Ethiopian calendar); Amharic: EC first. */
    private function when(CarbonInterface $t, string $lang, bool $withTime): string
    {
        $mode = (string) $this->settings->get('calendar', 'gregorian');
        $greg = $t->format('D j M Y').($withTime ? $t->format(', H:i') : '');
        $ec = EthiopianDate::format($t->toDateString(), $lang).($lang === 'am' ? ' ዓ.ም.' : ' EC');
        if ($lang === 'am') {
            return "{$ec} ({$t->format('j/n/Y')})".($withTime ? $t->format(', H:i') : '');
        }

        return $mode === 'gregorian' ? $greg : "{$greg} ({$ec})";
    }

    private function language(?Guest $guest): string
    {
        return in_array($guest?->language, MessageTemplates::LANGUAGES, true) ? $guest->language : $this->hotelLanguage();
    }

    private function hotelLanguage(): string
    {
        $l = (string) $this->settings->get('language', 'en');

        return in_array($l, MessageTemplates::LANGUAGES, true) ? $l : 'en';
    }

    private function already(Reservation $r, string $key, string $channel): bool
    {
        return GuestMessage::where('reservation_id', $r->id)->where('key', $key)->where('channel', $channel)->whereNull('sent_by')->whereIn('status', ['sent', 'failed'])->exists();
    }

    /** @param array{subject: string, body: string} $msg @return array{status: string, error: ?string} */
    private function email(Hotel $hotel, ?Reservation $r, ?BookingRequest $req, ?Guest $guest, string $key, string $lang, string $to, array $msg, ?User $by): array
    {
        $error = null;
        try {
            Mail::to($to)->send(new ReportMail($hotel->name, $msg['subject'], preg_split("/\n/", $msg['body'])));
        } catch (Throwable $e) {
            report($e);
            $error = 'The email could not be sent.';
        }

        return $this->log($r, $req, $guest, $key, 'email', $lang, $to, $error ? 'failed' : 'sent', $error, $by);
    }

    /** @return array{status: string, error: ?string} */
    private function smsSend(?Reservation $r, ?BookingRequest $req, ?Guest $guest, string $key, string $lang, string $to, string $text, ?User $by): array
    {
        $error = null;
        try {
            $this->sms->send($to, $text);
        } catch (RuntimeException $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
        }

        return $this->log($r, $req, $guest, $key, 'sms', $lang, $to, $error ? 'failed' : 'sent', $error, $by);
    }

    /** @return array{status: string, error: ?string} */
    private function opened(Reservation $r, ?Guest $guest, string $key, string $channel, string $lang, string $to, User $by): array
    {
        return $this->log($r, null, $guest, $key, $channel, $lang, $to, 'opened', null, $by);
    }

    /** @return array{status: string, error: ?string} */
    private function log(?Reservation $r, ?BookingRequest $req, ?Guest $guest, string $key, string $channel, string $lang, string $to, string $status, ?string $error, ?User $by): array
    {
        GuestMessage::create([
            'reservation_id' => $r?->id, 'booking_request_id' => $req?->id, 'guest_id' => $guest?->id ?? $r?->guest_id, 'key' => $key, 'channel' => $channel,
            'language' => $lang, 'to' => mb_substr($to, 0, 150), 'status' => $status, 'error' => $error, 'sent_by' => $by?->id,
        ]);

        return ['status' => $status, 'error' => $error];
    }
}
