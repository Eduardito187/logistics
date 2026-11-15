<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Shipments\Presentation\Http\Controller\DailyKpisController;
use Logistics\Shipments\Presentation\Http\Controller\GeocodeShipmentAddressController;
use Logistics\Shipments\Presentation\Http\Controller\ImportShipmentsController;
use Logistics\Shipments\Presentation\Http\Controller\ListLowConfidenceAddressesController;
use Logistics\Shipments\Presentation\Http\Controller\ListShipmentsController;
use Logistics\Shipments\Presentation\Http\Controller\LookupOrdersController;
use Logistics\Shipments\Presentation\Http\Controller\ReceiveShipmentController;
use Logistics\Shipments\Presentation\Http\Controller\RescheduleShipmentsController;
use Logistics\Shipments\Presentation\Http\Controller\ShipmentSummaryController;
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

    // Puerta app — ver pedidos, resumen del tablero, KPIs del día, revisar direcciones de baja confianza y
    // re-geocodificar.
    'app' => static function (): void {
        Route::name('shipments.')->group(function (): void {
            Route::get('shipments', ListShipmentsController::class)->name('index');
            // Resumen agregado del tablero (antes de {shipment} para que "summary" no se tome como id).
            Route::get('shipments/summary', ShipmentSummaryController::class)->name('summary');
            Route::get('shipments/low-confidence-addresses', ListLowConfidenceAddressesController::class)
                ->name('low-confidence-addresses');
            // Reprogramación (uno, varios, por camión o por ciudad). La vista previa la ven admin y
            // supervisor; APLICAR es solo de admin. Antes de {shipment} para que no se tome como id.
            Route::post('shipments/reschedule/preview', [RescheduleShipmentsController::class, 'preview'])
                ->middleware('role:supervisor,admin')
                ->name('reschedule.preview');
            Route::post('shipments/reschedule', [RescheduleShipmentsController::class, 'apply'])
                ->middleware('role:admin')
                ->name('reschedule.apply');
            Route::get('shipments/{shipment}', ShowShipmentController::class)->name('show');
            Route::post('shipments/{shipment}/geocode', GeocodeShipmentAddressController::class)
                ->middleware('order.scope')
                ->name('geocode');
        });

        // Consulta ABIERTA de pedidos por código o etiqueta: solo lectura, cualquier rol autenticado de la
        // app y SIN alcance por rol (a propósito no lleva middleware `role:`).
        Route::get('orders/lookup', LookupOrdersController::class)->name('orders.lookup');

        // Las escrituras de operación de un pedido o un envío (acá, en etiquetas y en ejecución) llevan el
        // middleware `order.scope`: 403 con `code` propio si no es del alcance del usuario (su ciudad, su
        // tienda o su asignación). Las lecturas de consulta de arriba quedan abiertas a propósito.

        // KPIs operativos del día (chofer / supervisor / tienda), alcance por rol server-side.
        Route::get('kpis/daily', DailyKpisController::class)->name('kpis.daily');
    },
];
