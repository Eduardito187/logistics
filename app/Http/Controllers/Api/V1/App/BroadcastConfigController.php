<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Support\Staff\StaffInbox;
use Illuminate\Http\JsonResponse;

/**
 * Datos que la app necesita para conectarse al tiempo real (protocolo Pusher), sin llevarlos fijos en el
 * binario: cada entorno tiene su propia clave pública.
 *
 *  - GET /api/v1/app/broadcasting/config
 *
 * El servidor de sockets se publica por el MISMO host y puerto que la API (el servidor web le pasa las
 * conexiones WebSocket de `/app/<clave>`): la app conecta a `ws(s)://<host de la API>/app/<key>`, con `wss`
 * cuando la API es https. Con `enabled: false` el entorno no tiene tiempo real y la app sigue con su
 * refresco normal.
 *
 * Solo viaja la clave PÚBLICA de la app de sockets; el secreto nunca sale del backend (firma las
 * suscripciones en `POST /api/v1/app/broadcasting/auth`).
 */
final class BroadcastConfigController
{
    public function __invoke(): JsonResponse
    {
        $key = config('broadcasting.connections.pusher.key');
        $cluster = config('broadcasting.connections.pusher.options.cluster');
        $enabled = StaffInbox::realtimeEnabled()
            && config('broadcasting.default') === 'pusher'
            && is_string($key) && $key !== '';

        return new JsonResponse([
            'enabled' => $enabled,
            'key' => $enabled ? $key : null,
            'cluster' => $enabled && is_string($cluster) && $cluster !== '' ? $cluster : null,
            'path' => '/app',
        ]);
    }
}
