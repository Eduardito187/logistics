<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Middleware\EnsureAppVersion;
use App\Models\AppOutboxFailure;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Reporte de acciones offline rechazadas (`POST /api/v1/app/outbox-failures`, pedido de la app 2026-10-16).
 *
 * La app es offline-first y por producto el usuario de campo no ve el estado offline: si el backend
 * rechaza para siempre una acción encolada (4xx no transitorio) o se agotan los reintentos, la fila queda
 * `failed` en el teléfono. Este endpoint recibe esos hechos en lote para que la torre de control los vea.
 *
 * - Usuario y dispositivo salen del token (nunca del cuerpo).
 * - Sin datos personales: solo metadatos de la operación; los ids de la ruta (`endpoint`) se enmascaran.
 * - Idempotente por `id` (la Idempotency-Key/ULID de la acción en el teléfono): reenviar el lote no
 *   duplica (INSERT … ON CONFLICT DO NOTHING).
 * - Responde 202 con el conteo de filas nuevas y duplicadas.
 */
final class OutboxFailureController
{
    /** Tamaño máximo del lote. */
    public const int MAX_BATCH = 100;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'failures' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'failures.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/', 'distinct'],
            'failures.*.queue' => ['required', 'string', Rule::in(AppOutboxFailure::QUEUES)],
            'failures.*.kind' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.:\-]+$/'],
            'failures.*.reason' => ['required', 'string', Rule::in(AppOutboxFailure::REASONS)],
            'failures.*.status' => ['nullable', 'integer', 'between:100,599'],
            'failures.*.problem_code' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'failures.*.endpoint' => ['nullable', 'string', 'max:255'],
            'failures.*.attempts' => ['nullable', 'integer', 'between:0,1000000'],
            'failures.*.occurred_at' => ['required', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->device_id : null;

        $version = $request->header(EnsureAppVersion::VERSION_HEADER);
        $appVersion = is_string($version) && trim($version) !== '' ? mb_substr(trim($version), 0, 40) : null;
        $now = Carbon::now();

        /** @var list<array<string, mixed>> $failures */
        $failures = $data['failures'];
        $rows = [];
        foreach ($failures as $failure) {
            // A la zona de la app: la columna es timestamp sin zona y el INSERT directo no convierte.
            $occurredAt = Carbon::parse((string) $failure['occurred_at'])->setTimezone($now->getTimezone());

            $rows[] = [
                'client_id' => (string) $failure['id'],
                'user_id' => $user->getKey(),
                'device_id' => $deviceId,
                'queue' => (string) $failure['queue'],
                'kind' => (string) $failure['kind'],
                'reason' => (string) $failure['reason'],
                'http_status' => isset($failure['status']) ? (int) $failure['status'] : null,
                'problem_code' => isset($failure['problem_code']) ? (string) $failure['problem_code'] : null,
                'endpoint' => isset($failure['endpoint']) ? self::maskEndpoint((string) $failure['endpoint']) : null,
                'attempts' => isset($failure['attempts']) ? (int) $failure['attempts'] : 0,
                'app_version' => $appVersion,
                // Un reloj del teléfono adelantado no puede fechar el hecho en el futuro.
                'occurred_at' => $occurredAt->greaterThan($now) ? $now : $occurredAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $stored = AppOutboxFailure::query()->insertOrIgnore($rows);

        return new JsonResponse(['data' => [
            'accepted' => true,
            'received' => count($rows),
            'stored' => $stored,
            'duplicates' => count($rows) - $stored,
        ]], 202);
    }

    /**
     * Deja solo la ruta (sin query ni fragmento, que pueden llevar datos) y reemplaza los identificadores
     * (UUID, ULID, números largos) por `{id}`: el admin nunca muestra UUIDs y la ruta queda agrupable.
     */
    public static function maskEndpoint(string $endpoint): string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', trim($endpoint));
        $path = (string) preg_replace('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', '{id}', $path);
        $path = (string) preg_replace('/(?<=\/)[0-9A-HJKMNP-TV-Z]{26}(?=\/|$)/', '{id}', $path);
        $path = (string) preg_replace('/(?<=\/)\d{4,}(?=\/|$)/', '{id}', $path);

        return mb_substr($path, 0, 255);
    }
}
