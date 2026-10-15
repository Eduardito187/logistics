<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Tracking\Presentation\Http\Controller\PublicTrackingController;
use Logistics\Tracking\Presentation\Http\Controller\PublicUnitsController;
use Logistics\Tracking\Presentation\Http\Controller\ShipmentTrackingController;
use Logistics\Tracking\Presentation\Http\Controller\ShipmentUnitsController;
use Logistics\Tracking\Presentation\Http\Controller\TruckPositionController;

/*
| Fragmento de rutas del contexto TRACKING.
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware + rate limit los pone routes/api.php). Rutas relativas a la puerta.
|
| Contrato en openapi/paths/tracking.yaml (fragmento), que el integrador fusiona en openapi/v1.yaml.
*/

return [
    // Puerta channels — página pública de rastreo por token opaco, sin PII.
    'channels' => static function (): void {
        Route::prefix('tracking')->name('tracking.')->group(function (): void {
            // Detalle per-bulto por token (sin PII): debe declararse antes que la ruta de un solo
            // segmento para que "{token}/units" no sea capturado como un token con guion bajo.
            Route::get('{token}/units', PublicUnitsController::class)
                ->name('public.units')
                ->where('token', '[A-Za-z0-9_-]+');

            Route::get('{token}', PublicTrackingController::class)
                ->name('public')
                ->where('token', '[A-Za-z0-9_-]+');
        });
    },

    // Puerta app — seguimiento interno por id de envío e ingesta de GPS del chofer.
    'app' => static function (): void {
        Route::prefix('tracking')->name('tracking.')->group(function (): void {
            Route::get('shipments/{shipmentId}', [ShipmentTrackingController::class, 'show'])
                ->name('shipment.show')
                ->where('shipmentId', '[0-9a-fA-F-]{36}');

            // Detalle per-bulto del envío (estado, ubicación y último escaneo de cada bulto).
            Route::get('shipments/{shipmentId}/units', [ShipmentUnitsController::class, 'index'])
                ->name('shipment.units')
                ->where('shipmentId', '[0-9a-fA-F-]{36}');

            Route::post('shipments/{shipmentId}/position', [TruckPositionController::class, 'store'])
                ->name('shipment.position.store')
                ->where('shipmentId', '[0-9a-fA-F-]{36}');
        });
    },

    // Puerta integrations — telemetría externa ya normalizada (credenciales de servicio).
    'integrations' => static function (): void {
        Route::prefix('tracking')->name('tracking.')->group(function (): void {
            Route::post('shipments/{shipmentId}/position', [TruckPositionController::class, 'store'])
                ->name('shipment.position.ingest')
                ->where('shipmentId', '[0-9a-fA-F-]{36}');
        });
    },
];
