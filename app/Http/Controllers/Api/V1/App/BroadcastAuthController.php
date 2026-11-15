<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Support\Staff\StaffInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Autorización de canales privados de tiempo real para la app (Bearer de Sanctum ligado a dispositivo).
 *
 *  - POST /api/v1/app/broadcasting/auth   firma la suscripción a un canal privado (protocolo Pusher)
 *
 * El `/broadcasting/auth` del framework autentica por sesión web (admin); la app no tiene sesión, así que
 * esta ruta hace lo mismo detrás de los middlewares de la puerta app (`auth:sanctum` + `device`). Quién
 * puede entrar a cada canal lo decide routes/channels.php (p. ej. `user.{id}`: solo el propio usuario).
 *
 * No hace I/O externo: la firma es un HMAC local con el secreto de la app de sockets.
 *
 * Cuerpo (JSON o form): `socket_id` (el que entrega el servidor de sockets al conectar) y `channel_name`
 * (con prefijo, p. ej. `private-user.42`). Respuesta 200: `{"auth": "<app_key>:<firma>"}`.
 */
final class BroadcastAuthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200', 'regex:/^(private|presence)-[A-Za-z0-9_\-=@,.;]+$/'],
        ]);

        if (!StaffInbox::realtimeEnabled()) {
            // Entorno sin servidor de sockets: la app sigue con el refresco normal de la bandeja.
            return ProblemDetails::fromCode(ErrorCode::ServiceUnavailable, 'Real-time updates are not enabled in this environment.')
                ->toResponse($request);
        }

        try {
            $signed = Broadcast::auth($request);
        } catch (AccessDeniedHttpException) {
            return ProblemDetails::fromCode(ErrorCode::Forbidden, 'You are not allowed to join this channel.')->toResponse($request);
        }

        $signed = is_array($signed) ? $signed : [];
        $auth = $signed['auth'] ?? null;
        if (!is_string($auth) || $auth === '') {
            // Transporte sin firma (p. ej. `log`): no hay a qué suscribirse.
            return ProblemDetails::fromCode(ErrorCode::ServiceUnavailable, 'Real-time updates are not enabled in this environment.')
                ->toResponse($request);
        }

        $response = ['auth' => $auth];
        $channelData = $signed['channel_data'] ?? null;
        if (is_string($channelData)) {
            // Solo en canales de presencia.
            $response['channel_data'] = $channelData;
        }

        return new JsonResponse($response);
    }
}
