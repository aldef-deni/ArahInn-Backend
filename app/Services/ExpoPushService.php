<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;

/**
 * Kirim push notification ke device via Expo Push API.
 * Endpoint: https://exp.host/--/api/v2/push/send
 *
 * Token format: ExponentPushToken[xxxxxxxx]
 * Tidak butuh API key — Expo authenticates via project ID di token-nya.
 */
class ExpoPushService
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const MAX_BATCH = 100;

    /**
     * Kirim push ke semua device aktif milik user.
     * Silent fail (logger warning) — push tidak boleh memblok flow utama.
     */
    public static function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        try {
            $tokens = DeviceToken::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('token')
                ->filter(fn($t) => str_starts_with($t, 'ExponentPushToken['))
                ->all();

            if (empty($tokens)) return;
            self::sendBatch($tokens, $title, $body, $data);
        } catch (\Throwable $e) {
            logger()->warning('ExpoPush::sendToUser failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Kirim ke banyak user.
     */
    public static function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        try {
            $tokens = DeviceToken::whereIn('user_id', array_unique($userIds))
                ->where('is_active', true)
                ->pluck('token')
                ->filter(fn($t) => str_starts_with($t, 'ExponentPushToken['))
                ->all();

            if (empty($tokens)) return;
            foreach (array_chunk($tokens, self::MAX_BATCH) as $chunk) {
                self::sendBatch($chunk, $title, $body, $data);
            }
        } catch (\Throwable $e) {
            logger()->warning('ExpoPush::sendToUsers failed', ['error' => $e->getMessage()]);
        }
    }

    private static function sendBatch(array $tokens, string $title, string $body, array $data): void
    {
        $messages = array_map(fn($token) => [
            'to'       => $token,
            'sound'    => 'default',
            'title'    => $title,
            'body'     => $body,
            'data'     => $data,
            'priority' => 'high',
            'channelId'=> 'default',
        ], $tokens);

        $response = Http::withHeaders([
                'Accept'            => 'application/json',
                'Accept-Encoding'   => 'gzip, deflate',
                'Content-Type'      => 'application/json',
            ])
            ->timeout(10)
            ->post(self::ENDPOINT, $messages);

        if (!$response->successful()) {
            logger()->warning('ExpoPush: non-2xx response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return;
        }

        // Cek per-ticket: kalau ada DeviceNotRegistered → deaktifkan token
        $tickets = $response->json('data') ?? [];
        foreach ($tickets as $idx => $ticket) {
            if (($ticket['status'] ?? null) === 'error') {
                $code = $ticket['details']['error'] ?? null;
                if ($code === 'DeviceNotRegistered') {
                    DeviceToken::where('token', $tokens[$idx] ?? null)
                        ->update(['is_active' => false]);
                }
            }
        }
    }
}
