<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Saved once (owner, QA 8 Nov). The app sends one Idempotency-Key per submission – a form submit, a tap – and the
 * same key again when it retries that submission (timeout, dropped connection). The server keeps key + user +
 * endpoint + a hash of the request + the answer for at least 24 hours (table `idempotency_keys`):
 *
 * - the same key again → the stored answer comes back, nothing new is saved (header `Idempotent-Replay: true`);
 * - the same key with a different request → refused (422, code `idempotency_mismatch`);
 * - two requests with the same key at the same moment → the unique index lets one run; the other waits for its
 *   answer (a few seconds) or gets 409 "still being saved" (code `busy`), which the app retries with the same key;
 * - an answer that is not a success (validation error, server error) is not kept: the key can be used again.
 *
 * Without a key (other clients, old app tabs) the request runs as before. Used on every route that takes money or
 * creates / changes a booking, bill, charge, cashier shift, guest or approval (routes/api.php).
 */
class Idempotent
{
    /** Answers are kept at least this long (the scheduler removes older rows: routes/console.php). */
    public const KEEP_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();
        if ($key === '' || ! $user) {
            return $next($request);
        }
        if (strlen($key) > 100) {
            return $this->refuse('The request key is too long.', 'idempotency_invalid', 422);
        }

        $endpoint = mb_substr($request->method().' '.$request->path(), 0, 255);
        $hash = hash('sha256', $request->getContent().'|'.$request->getQueryString());
        $table = DB::table('idempotency_keys');

        // Rows older than the keeping time no longer count (the scheduler deletes them later).
        (clone $table)->where('user_id', $user->id)->where('key', $key)->where('created_at', '<', now()->subHours(self::KEEP_HOURS))->delete();

        try {
            $id = (clone $table)->insertGetId([
                'user_id' => $user->id, 'key' => $key, 'endpoint' => $endpoint, 'request_hash' => $hash,
                'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($user->id, $key, $endpoint, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            (clone $table)->where('id', $id)->delete(); // nothing was saved: the key may be used again

            throw $e;
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() < 300) {
            (clone $table)->where('id', $id)->update([
                'status' => 'done', 'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(), 'updated_at' => now(),
            ]);
        } else {
            (clone $table)->where('id', $id)->delete();
        }

        return $response;
    }

    /** The key was used before (or is in use right now). */
    private function existing(int $userId, string $key, string $endpoint, string $hash): Response
    {
        $wait = (int) config('hotelms.idempotency_wait_ms', 8000);
        $deadline = microtime(true) + $wait / 1000;
        do {
            $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();
            if (! $row) {
                // The first attempt failed and freed the key in the meantime: let the app send it again.
                return $this->refuse('This is still being saved. Please wait a moment.', 'busy', 409);
            }
            if ($row->endpoint !== $endpoint || $row->request_hash !== $hash) {
                return $this->refuse('This request was already sent with different details. Reload the page and try again.', 'idempotency_mismatch', 422);
            }
            if ($row->status !== 'done' && strtotime((string) $row->updated_at) < now()->subMinutes(2)->getTimestamp()) {
                // A first attempt that died half-way (server stopped): free the key, the app's retry runs it again.
                DB::table('idempotency_keys')->where('id', $row->id)->where('status', 'processing')->delete();

                return $this->refuse('This is still being saved. Please wait a moment.', 'busy', 409);
            }
            if ($row->status === 'done') {
                return (new JsonResponse(json_decode((string) $row->response_body, true), (int) $row->response_status))
                    ->header('Idempotent-Replay', 'true');
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return $this->refuse('This is still being saved. Please wait a moment.', 'busy', 409);
    }

    private function refuse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code, 'errors' => ['idempotency' => [$message]]], $status);
    }
}
