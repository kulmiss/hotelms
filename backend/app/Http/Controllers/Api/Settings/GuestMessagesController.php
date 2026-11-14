<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Services\ActivityLogger;
use App\Services\HotelSettings;
use App\Services\Messages\GuestMessenger;
use App\Services\Messages\MessageTemplates;
use App\Services\Messages\SmsGateway;
use App\Services\Switches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Settings → Guest messages: the texts (English and Amharic), what goes out by itself, guest information, the SMS account. */
class GuestMessagesController extends Controller
{
    public function __construct(private MessageTemplates $templates, private GuestMessenger $messenger, private SmsGateway $sms, private HotelSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $messages = [];
        foreach (MessageTemplates::keys() as $key) {
            $row = ['key' => $key, 'consent' => config("guest_messages.messages.{$key}.consent")];
            foreach (MessageTemplates::LANGUAGES as $lang) {
                $row[$lang] = $this->templates->get($key, $lang);
            }
            $messages[] = $row;
        }

        return response()->json([
            'messages' => $messages,
            'placeholders' => config('guest_messages.placeholders'),
            'auto' => $this->messenger->auto(),
            'info' => $this->messenger->info(),
            'sms' => $this->sms->config(),
            'auto_sms' => app(Switches::class)->on($request->user()->hotel, 'feature.auto_sms'),
        ]);
    }

    public function saveTemplate(Request $request, string $key, string $lang): JsonResponse
    {
        abort_unless(in_array($key, MessageTemplates::keys(), true) && in_array($lang, MessageTemplates::LANGUAGES, true), 404);
        $data = $request->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:2000']]);
        MessageTemplate::updateOrCreate(['key' => $key, 'language' => $lang], $data);

        return response()->json(['data' => $this->templates->get($key, $lang)]);
    }

    /** Back to the default text. */
    public function resetTemplate(string $key, string $lang): JsonResponse
    {
        MessageTemplate::where('key', $key)->where('language', $lang)->get()->each->delete();

        return response()->json(['data' => $this->templates->get($key, $lang)]);
    }

    public function saveAuto(Request $request): JsonResponse
    {
        $rules = [];
        foreach (MessageTemplates::keys() as $key) {
            $rules["{$key}.email"] = ['required', 'boolean'];
            $rules["{$key}.sms"] = ['required', 'boolean'];
        }
        $this->settings->put([GuestMessenger::SETTINGS => $request->validate($rules)]);

        return response()->json(['data' => $this->messenger->auto()]);
    }

    public function saveInfo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wifi_name' => ['nullable', 'string', 'max:60'],
            'wifi_password' => ['nullable', 'string', 'max:60'],
            'breakfast' => ['nullable', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'menu_url' => ['nullable', 'string', 'max:300', 'url:https,http'],
        ], ['whatsapp.regex' => 'Write the WhatsApp number with digits, e.g. +251 911 234 567.', 'menu_url.url' => 'Paste the full link of your menu, starting with https://']);
        $this->settings->put([GuestMessenger::INFO => $data]);

        return response()->json(['data' => $this->messenger->info()]);
    }

    public function saveSms(Request $request, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(SmsGateway::PROVIDERS)],
            'token' => ['nullable', 'string', 'max:500'],
            'sender' => ['nullable', 'string', 'max:30'],
            'identifier' => ['nullable', 'string', 'max:60'],
            'url' => ['nullable', 'required_if:provider,http', 'string', 'max:500', 'regex:#^https?://[^\s/$.?\#][^\s]*$#i'], // {to} and {message} may be in it
            'method' => ['nullable', Rule::in(['GET', 'POST'])],
            'body' => ['nullable', 'string', 'max:1000'],
        ], ['url.regex' => 'Write the full address of your SMS provider, starting with https://']);
        if ($data['provider'] === 'afromessage' && blank($data['token'] ?? null) && ! $this->sms->config()['has_token']) {
            throw ValidationException::withMessages(['token' => 'Paste the API token from your AfroMessage account.']);
        }
        $this->sms->save($data);
        $log->log('settings.sms', 'SMS account changed: '.$data['provider']); // never the token

        return response()->json(['data' => $this->sms->config()]);
    }

    /** One test SMS to a number the hotel types (their own phone). */
    public function testSms(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{7,20}$/']]);
        try {
            $this->sms->send($data['phone'], 'Test from '.$request->user()->hotel->name.': your SMS account works.');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Sent.']);
    }
}
