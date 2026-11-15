<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Capacity\Presentation\Http\Controller\ConsumeCapacityController;
use Logistics\Capacity\Presentation\Http\Controller\DefineCapacityController;
use Logistics\Capacity\Presentation\Http\Controller\DeliveryCalendarController;
use Logistics\Capacity\Presentation\Http\Controller\GetAvailableCapacityController;
use Logistics\Capacity\Presentation\Http\Controller\GetEffectiveCapacityController;
use Logistics\Capacity\Presentation\Http\Controller\ListCapacityCalendarController;
use Logistics\Capacity\Presentation\Http\Controller\ReleaseCapacityController;

/*
| Fragmento de rutas del bounded context Capacity (cupos).
|
| Devuelve un mapa puerta => closure. El integrador (routes/api.php) invoca cada closure DENTRO del
| grupo de su puerta, que ya aplica el prefijo (/api/v1/<puerta>), el middleware de credenciales y el
| rate limit. Las rutas de aquí son relativas a la puerta.
|
| Las tres puertas (ver docs/architecture.md):
|   app           → app interna Flutter (token usuario + dispositivo, roles)
|   channels      → app de clientes, web, call center (credenciales por canal, rate limit)
|   integrations  → SAP, transportistas, servicios internos (credenciales de servicio)
*/

return [
    // Puerta app: operación interna consulta disponibilidad y calendario de cupos.
    'app' => static function (): void {
        Route::prefix('capacity')->name('capacity.')->group(function (): void {
            Route::get('available', GetAvailableCapacityController::class)->name('available');
            Route::get('effective', GetEffectiveCapacityController::class)->name('effective');
            Route::get('calendar', ListCapacityCalendarController::class)->name('calendar');
        });

        // Calendario de despacho para el admin de la app: fechas de envío, bloqueos y apertura de cupos
        // (lo mismo que la página «Dispatch calendar» del admin web). Solo admin y supervisor.
        Route::prefix('delivery-calendar')->name('delivery-calendar.')->middleware('role:supervisor,admin')->group(function (): void {
            Route::get('/', [DeliveryCalendarController::class, 'index'])->name('index');
            Route::post('blocked', [DeliveryCalendarController::class, 'block'])->name('block');
            Route::delete('blocked', [DeliveryCalendarController::class, 'unblock'])->name('unblock');
            Route::post('open', [DeliveryCalendarController::class, 'open'])->name('open');
            // Catálogos para armar la activación (municipios, franjas, unidades, ventanas por defecto).
            Route::get('options', [DeliveryCalendarController::class, 'options'])->name('options');
            // Habilita una fecha o rango con los cupos por defecto de las reglas (asíncrono).
            Route::post('open-default', [DeliveryCalendarController::class, 'openDefault'])->name('open-default');
        });
    },

    // Puerta channels: solo lectura para ofrecer fechas/franjas al cliente.
    'channels' => static function (): void {
        Route::prefix('capacity')->name('capacity.')->group(function (): void {
            Route::get('available', GetAvailableCapacityController::class)->name('available');
            Route::get('effective', GetEffectiveCapacityController::class)->name('effective');
            Route::get('calendar', ListCapacityCalendarController::class)->name('calendar');
        });
    },

    // Puerta integrations: aprovisionamiento define cupo; planning consume/libera.
    'integrations' => static function (): void {
        Route::prefix('capacity')->name('capacity.')->group(function (): void {
            Route::post('buckets', DefineCapacityController::class)->name('define');
            Route::post('consume', ConsumeCapacityController::class)->name('consume');
            Route::post('release', ReleaseCapacityController::class)->name('release');
        });
    },
];
