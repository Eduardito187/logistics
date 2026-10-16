<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de los dispositivos del propio usuario (`/api/v1/app/devices/*`).
 *
 * Permite listar los equipos ligados y revocar uno (robo/extravío), lo que borra sus
 * tokens y cierra su sesión.
 */
final class DeviceController
{
    /**
     * Lista los dispositivos del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $devices = $user->devices()
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(static fn (Device $device): array => [
                'uuid' => $device->uuid,
                'platform' => $device->platform,
                'name' => $device->name,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'revoked' => $device->isRevoked(),
            ])
            ->all();

        return new JsonResponse(['data' => $devices]);
    }

    /**
     * Revoca un dispositivo del usuario por su UUID.
     */
    public function revoke(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $device = $user->devices()->where('uuid', $uuid)->first();

        if ($device === null) {
            return new JsonResponse(['message' => 'Device not found.'], 404);
        }

        $device->revoke();

        return new JsonResponse(['message' => 'Device revoked.']);
    }

    /**
     * Actualiza el push token (FID de Firebase) del dispositivo en uso.
     *
     * El dispositivo se resuelve del `device_id` del token Sanctum en uso (igual que
     * {@see EnsureDeviceToken}), nunca de un parámetro: así cada
     * equipo solo puede tocar su propio token. `push_token` nulo da de baja al equipo
     * del push (p. ej. al cerrar sesión o al revocar el permiso de notificaciones).
     * PUT idempotente: repetir la llamada deja el mismo estado.
     */
    public function updatePushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['present', 'nullable', 'string', 'max:512'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if (!$token instanceof PersonalAccessToken || $token->device_id === null) {
            return new JsonResponse(['message' => 'Token is not bound to a device.'], 404);
        }

        $device = $user->devices()->whereKey($token->device_id)->first();

        if ($device === null) {
            return new JsonResponse(['message' => 'Device not found.'], 404);
        }

        $device->forceFill(['push_token' => $data['push_token']])->save();

        return new JsonResponse(null, 204);
    }
}
