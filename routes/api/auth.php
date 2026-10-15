<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\App\AuthController;
use App\Http\Controllers\Api\V1\App\DeviceController;
use App\Http\Middleware\AuthenticateChannel;
use App\Http\Middleware\AuthenticateServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fragmento de rutas: autenticación de las tres puertas (P1-AUTH-*)
|--------------------------------------------------------------------------
|
| Este archivo se incluye desde routes/api.php (lo hará el integrador):
|   require __DIR__.'/api/auth.php';
|
| Alias de middleware registrados en App\Providers\AuthServiceProvider:
|   device          → token de usuario ligado a un dispositivo vigente
|   role:<r1,r2>    → el usuario tiene alguno de esos roles
|   require.ability → el token tiene TODAS las habilidades indicadas
|   channel.auth    → credenciales de canal (X-Channel-Key / X-Channel-Secret)
|   service.auth    → credenciales de servicio (X-Client-Id / X-Client-Secret)
|
| Las habilidades por endpoint se exigen así en cada contexto:
|   ->middleware(['auth:sanctum', 'device', 'require.ability:execution:write'])
*/

// ── Puerta 1: app interna Flutter · /api/v1/app/* ──────────────────────────
Route::prefix('v1/app')->name('api.v1.app.')->group(function (): void {
    // Público: login por usuario + dispositivo (con límite anti fuerza bruta).
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    // Protegido: requiere token Sanctum válido ligado a un dispositivo vigente.
    Route::middleware(['auth:sanctum', 'device'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::delete('devices/{uuid}', [DeviceController::class, 'revoke'])->name('devices.revoke');
    });
});

// ── Puerta 2: canales (clientes/web/call center) · /api/v1/channels/* ──────
// Credenciales por canal + rate limit por canal.
Route::prefix('v1/channels')->name('api.v1.channels.')
    ->middleware([AuthenticateChannel::class, 'throttle:channels'])
    ->group(function (): void {
        // Verificación de credenciales del canal (útil para el cliente y el integrador).
        Route::get('auth/ping', function (Request $request): JsonResponse {
            $channel = $request->attributes->get(AuthenticateChannel::ATTRIBUTE);

            return new JsonResponse([
                'status' => 'ok',
                'channel' => $channel?->name,
            ]);
        })->name('auth.ping');
    });

// ── Puerta 3: integraciones (SAP/transportistas/webhooks) · /api/v1/integrations/* ──
// Credenciales de servicio; el scope se exige por ruta: service.auth:<scope>.
Route::prefix('v1/integrations')->name('api.v1.integrations.')
    ->middleware([AuthenticateServiceClient::class])
    ->group(function (): void {
        Route::get('auth/ping', function (Request $request): JsonResponse {
            $client = $request->attributes->get(AuthenticateServiceClient::ATTRIBUTE);

            return new JsonResponse([
                'status' => 'ok',
                'client' => $client?->name,
                'scopes' => $client?->scopes ?? [],
            ]);
        })->name('auth.ping');
    });
