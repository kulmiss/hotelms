<?php

namespace App\Services\Messages;

use App\Services\HotelSettings;
use App\Support\SafeHost;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * SMS through the hotel's own SMS account (owner, 14 Nov 2026): the hotel pays its provider, HotelMS charges nothing.
 * - AfroMessage (Ethiopia): POST https://api.afromessage.com/api/send, Bearer token, {to, message, from?, sender?},
 *   success when the answer says "acknowledge": "success" (as in the provider's SDKs; check with your account).
 * - Any other provider with a web address ("generic HTTP"): GET or POST, {to} and {message} in the address or body.
 * Only internet addresses; the token is stored encrypted and never shown again.
 */
class SmsGateway
{
    public const KEY = 'sms_gateway';

    public const PROVIDERS = ['none', 'afromessage', 'http'];

    public const AFROMESSAGE = 'https://api.afromessage.com/api/send';

    public function __construct(private HotelSettings $settings) {}

    /** @return array{provider: string, sender: ?string, identifier: ?string, url: ?string, method: string, body: ?string, has_token: bool} */
    public function config(): array
    {
        $c = (array) $this->settings->get(self::KEY, []);

        return [
            'provider' => in_array($c['provider'] ?? 'none', self::PROVIDERS, true) ? ($c['provider'] ?? 'none') : 'none',
            'sender' => $c['sender'] ?? null, 'identifier' => $c['identifier'] ?? null,
            'url' => $c['url'] ?? null, 'method' => strtoupper($c['method'] ?? 'POST') === 'GET' ? 'GET' : 'POST', 'body' => $c['body'] ?? null,
            'has_token' => filled($c['token'] ?? null),
        ];
    }

    public function ready(): bool
    {
        $c = $this->config();

        return match ($c['provider']) {
            'afromessage' => $c['has_token'],
            'http' => filled($c['url']),
            default => false,
        };
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        $old = (array) $this->settings->get(self::KEY, []);
        $token = array_key_exists('token', $data) && filled($data['token']) ? Crypt::encryptString((string) $data['token']) : ($old['token'] ?? null);
        if (($data['provider'] ?? null) === 'none') {
            $token = null;
        }
        $this->settings->put([self::KEY => [
            'provider' => $data['provider'] ?? 'none', 'sender' => $data['sender'] ?? null, 'identifier' => $data['identifier'] ?? null,
            'url' => $data['url'] ?? null, 'method' => $data['method'] ?? 'POST', 'body' => $data['body'] ?? null, 'token' => $token,
        ]]);
    }

    /** Send one SMS; throws with a short reason when the provider says no. */
    public function send(string $to, string $message): void
    {
        $c = $this->config();
        $token = $this->token();
        $number = preg_replace('/[^0-9+]/', '', $to);
        try {
            if ($c['provider'] === 'afromessage') {
                $res = Http::timeout(15)->withToken((string) $token)->acceptJson()
                    ->post(self::AFROMESSAGE, array_filter(['to' => $number, 'message' => $message, 'from' => $c['identifier'], 'sender' => $c['sender']]));
                if (! $res->successful() || $res->json('acknowledge') !== 'success') {
                    throw new RuntimeException('AfroMessage did not send it: '.mb_substr((string) ($res->json('response.errors.0') ?? $res->json('response') ?? $res->status()), 0, 120));
                }

                return;
            }
            if ($c['provider'] === 'http' && $c['url']) {
                $url = strtr($c['url'], ['{to}' => rawurlencode($number), '{message}' => rawurlencode($message)]);
                $host = parse_url($url, PHP_URL_HOST);
                if (! preg_match('#^https?://#i', $url) || ! $host || ! SafeHost::isPublic($host)) {
                    throw new RuntimeException('The SMS address must be on the internet (https://…).');
                }
                $req = Http::timeout(15)->withOptions(['allow_redirects' => false]);
                if ($token) {
                    $req = $req->withToken($token);
                }
                $res = $c['method'] === 'GET' ? $req->get($url)
                    : $req->withBody(strtr((string) ($c['body'] ?: '{"to":"{to}","message":"{message}"}'), ['{to}' => $this->json($number), '{message}' => $this->json($message)]), 'application/json')->post($url);
                if (! $res->successful()) {
                    throw new RuntimeException("The SMS provider answered with an error ({$res->status()}).");
                }

                return;
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('The SMS provider could not be reached.');
        }
        throw new RuntimeException('No SMS account is set up.');
    }

    private function token(): ?string
    {
        $enc = ((array) $this->settings->get(self::KEY, []))['token'] ?? null;
        try {
            return $enc ? Crypt::decryptString($enc) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** A value inside a JSON string (without the quotes). */
    private function json(string $v): string
    {
        return substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    }
}
