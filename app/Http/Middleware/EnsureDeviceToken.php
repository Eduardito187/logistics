<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda de la puerta `/api/v1/app/*`: exige token de usuario ligado a un dispositivo
 * vigente (P1-AUTH-01).
 *
 * Se ejecuta DESPUÉS de `auth:sanctum` (lo wirea el integrador). Verifica que el token
 * esté atado a un dispositivo, que el dispositivo no esté revocado y que su binding
 * coincida con el usuario autenticado; además registra la última actividad del equipo.
 *
 * Camino caliente (la app sondea seguido): en lugar de leer el dispositivo por PK en CADA request
 * (`Device::find`), se cachea en Redis un snapshot primitivo del estado "device vigente/revocado"
 * con TTL corto (M-11 de la auditoría de performance 2026-10-20). Así la mayoría de los requests
 * validan sin tocar la base. La invalidación en vivo la dispara `Device::revoke()` (que además borra
 * los tokens, con lo que `auth:sanctum` ya corta el acceso antes de llegar aquí); el TTL corto acota
 * cualquier otra ventana de desfase. Se cachea la forma primitiva (array), nunca el modelo (regla 7).
 */
final class EnsureDeviceToken
{
    /**
     * TTL del snapshot del dispositivo en cache. Corto a propósito: acota la ventana de desfase si el
     * estado cambia por un camino que no invalida explícitamente (la revocación sí invalida en vivo).
     */
    public const int DEVICE_CACHE_TTL_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->problem($request, ErrorCode::Unauthenticated, 'Unauthenticated.');
        }

        $token = $user->currentAccessToken();

        if (!$token instanceof PersonalAccessToken || $token->device_id === null) {
            return $this->problem($request, ErrorCode::Unauthenticated, 'Token is not bound to a device.');
        }

        $deviceId = $token->device_id;
        $snapshot = $this->deviceSnapshot($deviceId);

        if ($snapshot === null || $snapshot['revoked']) {
            return $this->problem($request, ErrorCode::DeviceRevoked, 'Device has been revoked.');
        }

        if ($snapshot['user_id'] !== null && $snapshot['user_id'] !== $user->getKey()) {
            return $this->problem($request, ErrorCode::DeviceRevoked, 'Device is bound to another user.');
        }

        $this->touchLastSeen($deviceId);

        return $next($request);
    }

    /**
     * Clave de cache del snapshot de un dispositivo. Pública para que la invalidación en vivo
     * ({@see Device::revoke()}) reutilice exactamente la misma convención.
     */
    public static function deviceCacheKey(int | string $deviceId): string
    {
        return 'app:device:active:'.$deviceId;
    }

    /**
     * Invalida el snapshot cacheado de un dispositivo (p. ej. al revocarlo).
     */
    public static function forgetDevice(int | string $deviceId): void
    {
        Cache::forget(self::deviceCacheKey($deviceId));
    }

    /**
     * Snapshot primitivo del dispositivo desde cache (con TTL corto); `null` si el equipo no existe.
     *
     * @return array{revoked: bool, user_id: int|null}|null
     */
    private function deviceSnapshot(int $deviceId): ?array
    {
        $key = self::deviceCacheKey($deviceId);
        $cached = Cache::get($key);

        if (!is_array($cached)) {
            return $this->deviceSnapshotFromDatabase($deviceId, $key);
        }

        // Un equipo inexistente se cachea igual (negative caching) para no re-consultar en cada hit.
        if (($cached['exists'] ?? false) !== true) {
            return null;
        }

        return ['revoked' => (bool) $cached['revoked'], 'user_id' => $cached['user_id']];
    }

    /**
     * Lee el dispositivo de la base y cachea su snapshot (positivo o negativo, con el mismo TTL corto);
     * `null` si el equipo no existe. Separa el camino frío del hit en cache para no pasar de 3 returns.
     *
     * @return array{revoked: bool, user_id: int|null}|null
     */
    private function deviceSnapshotFromDatabase(int $deviceId, string $key): ?array
    {
        $device = Device::find($deviceId);

        if ($device === null) {
            Cache::put($key, ['exists' => false], self::DEVICE_CACHE_TTL_SECONDS);

            return null;
        }

        $snapshot = ['revoked' => $device->isRevoked(), 'user_id' => $device->user_id];
        Cache::put($key, ['exists' => true] + $snapshot, self::DEVICE_CACHE_TTL_SECONDS);

        return $snapshot;
    }

    /**
     * Marca el dispositivo como visto ahora, a lo sumo una vez por ventana, SIN leerlo antes.
     *
     * La ventana se controla con un marcador en cache (no leyendo `last_seen_at` de la base): así el
     * camino caliente no hace ni SELECT ni UPDATE salvo una vez por {@see Device::LAST_SEEN_RESOLUTION_SECONDS}.
     * El UPDATE directo evita materializar el modelo (el snapshot vive en cache como primitivo).
     */
    private function touchLastSeen(int $deviceId): void
    {
        $marker = 'app:device:seen:'.$deviceId;

        if (Cache::get($marker) !== null) {
            return;
        }

        Cache::put($marker, true, Device::LAST_SEEN_RESOLUTION_SECONDS);
        Device::query()->whereKey($deviceId)->update(['last_seen_at' => now()]);
    }

    private function problem(Request $request, ErrorCode $code, string $detail): Response
    {
        return ProblemDetails::fromCode($code, $detail)->toResponse($request);
    }
}
