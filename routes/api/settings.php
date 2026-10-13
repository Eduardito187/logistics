<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Settings\Presentation\Http\Controller\GetSettingController;
use Logistics\Settings\Presentation\Http\Controller\GetSettingHistoryController;
use Logistics\Settings\Presentation\Http\Controller\SetSettingController;

/*
| Fragmento de rutas del bounded context Settings (configuración versionada).
|
| Devuelve un mapa puerta => closure. El integrador (routes/api.php) invoca cada closure DENTRO del
| grupo de su puerta, que ya aplica el prefijo (/api/v1/<puerta>), el middleware de credenciales y el
| rate limit. Las rutas de aquí son relativas a la puerta.
|
| Las tres puertas (ver docs/architecture.md):
|   app           → app interna Flutter (token usuario + dispositivo, roles)
|   channels      → app de clientes, web, call center (credenciales por canal, rate limit)
|   integrations  → SAP, transportistas, servicios internos (credenciales de servicio)
|
| Settings es plomería de plataforma: la operación interna LEE reglas vigentes e historial (puerta
| app); publicar una nueva versión es una acción de servicio/administración (puerta integrations).
| La clave namespaced lleva punto, por eso el parámetro de ruta usa la restricción `.*`.
*/

return [
    // Puerta app: la operación interna consulta el valor vigente y el historial de una regla.
    'app' => static function (): void {
        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('{key}/history', GetSettingHistoryController::class)
                ->where('key', '.*')
                ->name('history');
            Route::get('{key}', GetSettingController::class)
                ->where('key', '.*')
                ->name('get');
        });
    },

    // Puerta integrations: servicios/administración publican una nueva versión de una regla.
    'integrations' => static function (): void {
        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::post('/', SetSettingController::class)->name('set');
            Route::get('{key}/history', GetSettingHistoryController::class)
                ->where('key', '.*')
                ->name('history');
            Route::get('{key}', GetSettingController::class)
                ->where('key', '.*')
                ->name('get');
        });
    },
];
