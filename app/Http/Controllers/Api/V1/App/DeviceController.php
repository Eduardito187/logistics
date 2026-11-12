<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
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
     *
     * Además de los campos originales, cada equipo trae lo necesario para distinguir teléfonos
     * (auditoría app #28): `display_name` (nombre del usuario, o modelo, o plataforma), `model`,
     * `os_version`, `app_version`, `app_build` e `is_current` (el equipo del token en uso). Aditivo.
     * `last_ip` (última IP desde la que se usó el equipo) solo sale aquí: la lista es SIEMPRE la de los
     * equipos del propio usuario autenticado, así que nadie ve la IP de equipos ajenos.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->currentDeviceId($user);

        $devices = $user->devices()
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(static fn (Device $device): array => self::present($device, $currentId))
            ->all();

        return new JsonResponse(['data' => $devices]);
    }

    /**
     * Renombra un dispositivo PROPIO (`PATCH /api/v1/app/devices/{uuid}`; `current` = el del token en uso).
     *
     * Solo el dueño: el equipo se busca entre los dispositivos del usuario autenticado, así el de otro
     * usuario responde 404 (no se revela que existe). `name` nulo/vacío vuelve al nombre derivado.
     */
    public function rename(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'name' => ['present', 'nullable', 'string', 'max:80'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $currentId = $this->currentDeviceId($user);

        if ($uuid === 'current') {
            $device = $currentId === null ? null : $user->devices()->whereKey($currentId)->first();
        } else {
            $device = $user->devices()->where('uuid', $uuid)->first();
        }

        if (!$device instanceof Device) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'Device not found.')->toResponse($request);
        }

        $name = is_string($data['name']) ? trim($data['name']) : '';
        $device->forceFill(['name' => $name === '' ? null : $name])->save();

        return new JsonResponse(['data' => self::present($device, $currentId)]);
    }

    /**
     * Forma pública de un dispositivo en la lista.
     *
     * @return array<string, mixed>
     */
    private static function present(Device $device, ?int $currentId): array
    {
        return [
            'uuid' => $device->uuid,
            'platform' => $device->platform,
            'name' => $device->name,
            'display_name' => $device->displayName(),
            'model' => $device->model,
            'os_version' => $device->os_version,
            'app_version' => $device->app_version,
            'app_build' => $device->app_build,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_ip' => $device->last_ip,
            'revoked' => $device->isRevoked(),
            'is_current' => $currentId !== null && $device->getKey() === $currentId,
        ];
    }

    /**
     * Id interno del dispositivo ligado al token en uso (null si el token no está atado a uno).
     */
    private function currentDeviceId(User $user): ?int
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->device_id !== null ? (int) $token->device_id : null;
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
