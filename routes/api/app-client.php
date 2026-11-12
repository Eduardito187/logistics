<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\App\AppConfigController;
use App\Http\Controllers\Api\V1\App\DeviceController;
use App\Http\Controllers\Api\V1\App\OutboxFailureController;
use App\Http\Controllers\Api\V1\App\PreferencesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fragmento de rutas: soporte del cliente de la app interna (auditoría app 2026-11-12)
|--------------------------------------------------------------------------
|
| Autocontenido (como auth.php): define sus propios grupos porque `app-config` es PÚBLICO (la app lo
| consulta antes del login para saber si debe actualizarse). Se incluye desde routes/api.php:
|   require __DIR__.'/api/app-client.php';
|
| - GET   app-config            → configuración dictada por el servidor (público, throttle)
| - GET   me/preferences        → preferencias del usuario
| - PUT   me/preferences        → merge last-write-wins por clave
| - POST  outbox-failures       → reporte en lote de acciones offline rechazadas (idempotente por id)
| - PATCH devices/{uuid}        → renombrar un dispositivo propio (`current` = el del token)
|
| La versión mínima (X-Min-App-Version / 426) la aplica el middleware global EnsureAppVersion.
*/

Route::prefix('v1/app')->name('api.v1.app.')->group(function (): void {
    Route::get('app-config', AppConfigController::class)
        ->middleware('throttle:60,1')
        ->name('app-config');

    // Mismo middleware que la puerta app (token ligado a dispositivo vigente + idempotencia offline).
    Route::middleware(['auth:sanctum', 'device', 'idempotency'])->group(function (): void {
        Route::get('me/preferences', [PreferencesController::class, 'show'])->name('me.preferences.show');
        Route::put('me/preferences', [PreferencesController::class, 'update'])->name('me.preferences.update');

        Route::post('outbox-failures', [OutboxFailureController::class, 'store'])->name('outbox-failures.store');

        Route::patch('devices/{uuid}', [DeviceController::class, 'rename'])->name('devices.rename');
    });
});
