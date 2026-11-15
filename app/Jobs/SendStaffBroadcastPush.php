<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Logistics\Notifications\Infrastructure\Fcm\FcmAccessToken;
use stdClass;
use Throwable;

/**
 * Envía el PUSH de un aviso interno a los dispositivos de sus destinatarios (FCM, por token de dispositivo).
 *
 * Corre en la cola `notifications`, nunca en el request de quien envía el aviso (regla: nada de I/O externo
 * síncrono). El aviso ya quedó en la bandeja de cada persona al crearse: el push es solo el toque en el
 * teléfono. Por eso es tolerante: si FCM no está configurado en este entorno, no hace nada y no falla; un
 * dispositivo que rechaza el push no corta a los demás. Idempotente: solo empuja a quien todavía no tiene
 * `pushed_at`, así un reintento del job no duplica.
 */
final class SendStaffBroadcastPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $broadcastId,
    ) {
        // Misma cola que los avisos al cliente (su nombre real sale de la config de observabilidad).
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        $broadcast = DB::table('staff_broadcasts')->where('id', $this->broadcastId)->first();
        if (!$broadcast instanceof stdClass) {
            return;
        }

        /** @var array<string, mixed> $config */
        $config = (array) config('logistics-notifications.fcm', []);
        $projectId = is_string($config['project_id'] ?? null) ? trim($config['project_id']) : '';
        $credentials = is_string($config['credentials'] ?? null) ? trim($config['credentials']) : '';
        if ($projectId === '' || $credentials === '') {
            // Sin FCM configurado el aviso vive solo en la bandeja (funciona igual en cualquier entorno).
            return;
        }

        $timeout = (int) ($config['timeout'] ?? 10);
        $token = (new FcmAccessToken(['project_id' => $projectId, 'credentials' => $credentials, 'timeout' => $timeout]))->value();
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $pending = DB::table('staff_broadcast_recipients')
            ->where('broadcast_id', $this->broadcastId)
            ->whereNull('pushed_at')
            ->pluck('user_id')
            ->all();

        foreach ($pending as $userId) {
            $tokens = DB::table('devices')
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->whereNotNull('push_token')
                ->pluck('push_token')
                ->all();

            foreach ($tokens as $pushToken) {
                if (!is_string($pushToken) || $pushToken === '') {
                    continue;
                }

                try {
                    Http::withToken($token)->timeout($timeout)->connectTimeout(3)->post($endpoint, ['message' => [
                        'token' => $pushToken,
                        'notification' => ['title' => (string) $broadcast->title, 'body' => (string) $broadcast->body],
                        'data' => [
                            'type' => 'staff_broadcast',
                            'broadcast_id' => $this->broadcastId,
                            'link' => is_string($broadcast->link ?? null) ? $broadcast->link : '',
                        ],
                        'android' => ['priority' => 'high'],
                    ]]);
                } catch (Throwable $e) {
                    Log::warning('staff broadcast push failed', ['broadcast_id' => $this->broadcastId, 'error' => $e->getMessage()]);
                }
            }

            DB::table('staff_broadcast_recipients')
                ->where('broadcast_id', $this->broadcastId)
                ->where('user_id', $userId)
                ->update(['pushed_at' => now(), 'updated_at' => now()]);
        }
    }
}
