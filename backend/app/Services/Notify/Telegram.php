<?php

namespace App\Services\Notify;

use App\Models\PlatformSetting;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telegram bot (free). No webhook is needed: cron reads new messages every minute
 * (getUpdates), so it works on shared hosting. A person links their chat by sending
 * "/start CODE" to the bot.
 */
class Telegram
{
    private function token(): ?string
    {
        $t = PlatformSetting::read('telegram_bot_token');

        return is_string($t) && $t !== '' ? $t : null;
    }

    public function enabled(): bool
    {
        return $this->token() !== null;
    }

    public function botUsername(): ?string
    {
        return PlatformSetting::read('telegram_bot_username');
    }

    /** A fresh one-time code (valid 30 minutes) and the link that opens the bot with it. */
    public function linkCode(User $user): array
    {
        $code = strtoupper(Str::random(8));
        TelegramLink::updateOrCreate(['user_id' => $user->id], ['hotel_id' => $user->hotel_id, 'code' => $code, 'code_expires_at' => now()->addMinutes(30)]);
        $bot = $this->botUsername();

        return ['code' => $code, 'url' => $bot ? "https://t.me/{$bot}?start={$code}" : null, 'bot' => $bot];
    }

    /** Read new messages and link chats that sent a valid code. Returns how many were linked. */
    public function poll(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $offset = (int) (PlatformSetting::read('telegram_offset') ?? 0);
        $response = $this->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message']]);
        $linked = 0;
        foreach ($response['result'] ?? [] as $update) {
            $offset = max($offset, (int) $update['update_id'] + 1);
            $text = trim((string) ($update['message']['text'] ?? ''));
            $chat = $update['message']['chat'] ?? null;
            if (! $chat || ! preg_match('/^\/start\s+([A-Z0-9]{8})$/i', $text, $m)) {
                continue;
            }
            $link = TelegramLink::where('code', strtoupper($m[1]))->where('code_expires_at', '>', now())->first();
            if (! $link) {
                $this->send((string) $chat['id'], 'This code is not valid any more. Make a new one in your profile.');

                continue;
            }
            $link->update([
                'chat_id' => (string) $chat['id'],
                'chat_name' => trim(($chat['title'] ?? '').' '.($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')),
                'linked_at' => now(), 'code' => null, 'code_expires_at' => null,
            ]);
            $this->send((string) $chat['id'], 'Linked. You will get your reports and alerts here.');
            $linked++;
        }
        PlatformSetting::write('telegram_offset', $offset);

        return $linked;
    }

    public function chatFor(User $user): ?string
    {
        return TelegramLink::where('user_id', $user->id)->whereNotNull('chat_id')->value('chat_id');
    }

    public function send(string $chatId, string $text): bool
    {
        return (bool) ($this->call('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true])['ok'] ?? false);
    }

    public function sendDocument(string $chatId, string $filename, string $content, string $caption = ''): bool
    {
        $token = $this->token();
        if (! $token) {
            return false;
        }
        try {
            $r = Http::timeout(30)->attach('document', $content, $filename)
                ->post("https://api.telegram.org/bot{$token}/sendDocument", ['chat_id' => $chatId, 'caption' => mb_substr($caption, 0, 1000)]);

            return (bool) $r->json('ok');
        } catch (Throwable $e) {
            Log::warning('Telegram sendDocument failed: '.$e->getMessage());

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function call(string $method, array $params): array
    {
        $token = $this->token();
        if (! $token) {
            return [];
        }
        try {
            return Http::timeout(15)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $params)->json() ?? [];
        } catch (Throwable $e) {
            Log::warning("Telegram {$method} failed: ".$e->getMessage());

            return [];
        }
    }
}
