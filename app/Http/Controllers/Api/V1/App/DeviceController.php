<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Models\Device;
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
}
