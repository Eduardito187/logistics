<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\App\AuthController;
use App\Http\Controllers\Api\V1\App\BroadcastAuthController;
use App\Http\Controllers\Api\V1\App\BroadcastConfigController;
use App\Http\Controllers\Api\V1\App\DeviceController;
use App\Http\Controllers\Api\V1\App\PasswordResetController;
use App\Http\Controllers\Api\V1\App\StaffBroadcastController;
use App\Http\Controllers\Api\V1\App\StaffController;
use App\Http\Controllers\Api\V1\App\TwoFactorController;
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

    // Público: segundo paso del login con 2FA. Completa el reto emitido por login y, si
    // el código (TOTP o recovery) es válido, emite el token definitivo. Mismo throttle
    // que el login para frenar la fuerza bruta sobre el código.
    Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('auth.2fa.verify');

    // Público: reseteo de contraseña por OTP (con límite anti fuerza bruta / enumeración).
    Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:5,1')
        ->name('auth.password.forgot');
    Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')
        ->name('auth.password.reset');

    // Protegido: requiere token Sanctum válido ligado a un dispositivo vigente.
    Route::middleware(['auth:sanctum', 'device'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Gestión del segundo factor del propio usuario (enrolar / confirmar / deshabilitar).
        Route::post('auth/2fa/enroll', [TwoFactorController::class, 'enroll'])->name('auth.2fa.enroll');
        Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
        Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');

        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::put('devices/current/push-token', [DeviceController::class, 'updatePushToken'])
            ->name('devices.push-token.update');
        Route::delete('devices/{uuid}', [DeviceController::class, 'revoke'])->name('devices.revoke');

        // Tiempo real: firma la suscripción de la app a un canal privado (p. ej. `private-user.{id}`, por
        // donde llega `inbox.updated`). Mismo Bearer de la puerta app; quién entra a cada canal lo decide
        // routes/channels.php.
        Route::post('broadcasting/auth', BroadcastAuthController::class)->name('broadcasting.auth');
        // Clave pública y ruta del servidor de sockets de ESTE entorno (la app no las lleva fijas).
        Route::get('broadcasting/config', BroadcastConfigController::class)->name('broadcasting.config');

        // Avisos INTERNOS al personal: enviar es solo de admin; ver los enviados, admin y supervisor.
        Route::post('broadcasts', [StaffBroadcastController::class, 'send'])
            ->middleware('role:admin')->name('broadcasts.send');
        Route::get('broadcasts', [StaffBroadcastController::class, 'sent'])
            ->middleware('role:supervisor,admin')->name('broadcasts.sent');
        // Bandeja propia de avisos internos (cualquier rol).
        Route::get('inbox', [StaffBroadcastController::class, 'inbox'])->name('inbox.index');
        Route::post('inbox/read-all', [StaffBroadcastController::class, 'readAll'])->name('inbox.read-all');
        Route::post('inbox/{broadcastId}/read', [StaffBroadcastController::class, 'read'])
            ->whereUuid('broadcastId')->name('inbox.read');

        // Ficha del PERSONAL (cualquier rol, una sola vista): solo admin y supervisor.
        Route::prefix('staff')->name('staff.')->middleware('role:supervisor,admin')->group(function (): void {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::get('{userId}', [StaffController::class, 'show'])->whereNumber('userId')->name('show');
            Route::get('{userId}/documents', [StaffController::class, 'documents'])->whereNumber('userId')->name('documents.index');
            Route::post('{userId}/documents', [StaffController::class, 'storeDocument'])->whereNumber('userId')->name('documents.store');
            Route::get('{userId}/activity', [StaffController::class, 'activity'])->whereNumber('userId')->name('activity');
            Route::get('{userId}/photos', [StaffController::class, 'photos'])->whereNumber('userId')->name('photos');
            Route::put('{userId}/node', [StaffController::class, 'assignNode'])->whereNumber('userId')->name('node.assign');
            Route::put('{userId}/profile', [StaffController::class, 'updateProfile'])->whereNumber('userId')->name('profile.update');
            Route::post('{userId}/photo', [StaffController::class, 'uploadPhoto'])->whereNumber('userId')->name('photo.upload');
        });
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
