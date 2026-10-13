<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Shipments\Presentation\Http\Controller\GeocodeShipmentAddressController;
use Logistics\Shipments\Presentation\Http\Controller\ImportShipmentsController;
use Logistics\Shipments\Presentation\Http\Controller\ListLowConfidenceAddressesController;
use Logistics\Shipments\Presentation\Http\Controller\ListShipmentsController;
use Logistics\Shipments\Presentation\Http\Controller\ReceiveShipmentController;
use Logistics\Shipments\Presentation\Http\Controller\ShowShipmentController;

/*
| Fragmento de rutas del contexto shipments (recepción de pedidos).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta. Los controllers invocan
| Application vía CommandBus/QueryBus del contenedor.
*/

return [
    // Puerta integrations — SAP / Magento / webhooks: ingreso único e idempotente de pedidos.
    'integrations' => static function (): void {
        Route::name('shipments.')->group(function (): void {
            Route::post('shipments', ReceiveShipmentController::class)->name('receive');
            // Carga masiva desde planilla CSV/Excel (SHP-06): ingreso fila por fila, idempotente.
            Route::post('shipments/import', ImportShipmentsController::class)->name('import');
        });
    },

    // Puerta app — ver pedidos, revisar direcciones de baja confianza y re-geocodificar.
    'app' => static function (): void {
        Route::name('shipments.')->group(function (): void {
            Route::get('shipments', ListShipmentsController::class)->name('index');
            Route::get('shipments/low-confidence-addresses', ListLowConfidenceAddressesController::class)
                ->name('low-confidence-addresses');
            Route::get('shipments/{shipment}', ShowShipmentController::class)->name('show');
            Route::post('shipments/{shipment}/geocode', GeocodeShipmentAddressController::class)->name('geocode');
        });
    },
];
