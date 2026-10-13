<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda de la puerta `/api/v1/app/*`: exige token de usuario ligado a un dispositivo
 * vigente (P1-AUTH-01).
 *
 * Se ejecuta DESPUÉS de `auth:sanctum` (lo wirea el integrador). Verifica que el token
 * esté atado a un dispositivo, que el dispositivo no esté revocado y que su binding
 * coincida con el usuario autenticado; además registra la última actividad del equipo.
 */
final class EnsureDeviceToken
{
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

        $device = Device::find($token->device_id);

        if ($device === null || $device->isRevoked()) {
            return $this->problem($request, ErrorCode::DeviceRevoked, 'Device has been revoked.');
        }

        if ($device->user_id !== null && $device->user_id !== $user->getKey()) {
            return $this->problem($request, ErrorCode::DeviceRevoked, 'Device is bound to another user.');
        }

        $device->touchLastSeen();

        return $next($request);
    }

    private function problem(Request $request, ErrorCode $code, string $detail): Response
    {
        return ProblemDetails::fromCode($code, $detail)->toResponse($request);
    }
}
