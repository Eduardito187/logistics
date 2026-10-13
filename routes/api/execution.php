<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Execution\Presentation\Http\AssembleDailyRouteController;
use Logistics\Execution\Presentation\Http\GetDeliveryStateController;
use Logistics\Execution\Presentation\Http\GetDriverRouteController;
use Logistics\Execution\Presentation\Http\GetNodeReceptionController;
use Logistics\Execution\Presentation\Http\ListVehicleDeliveriesController;
use Logistics\Execution\Presentation\Http\OpenNodeReceptionController;
use Logistics\Execution\Presentation\Http\ReceiveUnitAtNodeController;
use Logistics\Execution\Presentation\Http\ReconcileNodeReceptionController;
use Logistics\Execution\Presentation\Http\RecordFailedDeliveryController;
use Logistics\Execution\Presentation\Http\RecordLoadScanController;
use Logistics\Execution\Presentation\Http\RecordNodeReceptionController;
use Logistics\Execution\Presentation\Http\RecordPreparationScanController;
use Logistics\Execution\Presentation\Http\RecordProofOfDeliveryController;
use Logistics\Execution\Presentation\Http\RecordUnloadScanController;
use Logistics\Execution\Presentation\Http\ReportVehicleIncidentController;
use Logistics\Execution\Presentation\Http\UploadDeliveryPhotoController;
use Logistics\Execution\Presentation\Http\VerifyLabelController;

/*
| Fragmento de rutas del contexto Ejecución (logistics/execution).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta.
| Todos los endpoints pertenecen a la puerta app (la ejecución en terreno la registra solo la app
| interna Flutter). Rutas relativas a la puerta.
|
| Todos los escaneos son idempotentes (header `Idempotency-Key` o campo idempotency_key) y
| offline-first: el reenvío del mismo escaneo no duplica el hecho.
*/

return [
    // Puerta app — escaneos, ruta, POD, fallidas y recepción en nodos (app interna Flutter).
    'app' => static function (): void {
        Route::name('execution.')->group(function (): void {
            // Hoja de ruta del chofer: entregas asignadas a un vehículo.
            Route::get('vehicles/{vehicleId}/deliveries', ListVehicleDeliveriesController::class)
                ->name('vehicles.deliveries');

            // "Mi ruta" del chofer: secuencia ordenada de paradas, parada actual y restantes.
            // GET con ?date=YYYY-MM-DD (opcional); sin fecha, la ruta abierta vigente del camión.
            Route::get('vehicles/{vehicleId}/route', GetDriverRouteController::class)
                ->name('vehicles.route.show');

            // Armar la ruta del día del camión a partir de las paradas del plan publicado.
            Route::post('vehicles/{vehicleId}/route', AssembleDailyRouteController::class)
                ->name('vehicles.route.assemble');

            // Reporte de incidente del chofer (avería/accidente): dispara la reacción automática
            // (fuera de servicio + reprogramación + avisos). Idempotente por Idempotency-Key.
            Route::post('vehicles/{vehicleId}/incidents', ReportVehicleIncidentController::class)
                ->name('vehicles.incidents.report');

            // Estado actual de la entrega de un envío (con sus escaneos).
            Route::get('deliveries/{shipmentId}', GetDeliveryStateController::class)
                ->name('deliveries.show');

            // Escaneos de terreno (idempotentes, offline-first).
            Route::post('deliveries/scans/preparation', RecordPreparationScanController::class)
                ->name('scans.preparation');
            Route::post('deliveries/scans/load', RecordLoadScanController::class)
                ->name('scans.load');
            Route::post('deliveries/scans/unload', RecordUnloadScanController::class)
                ->name('scans.unload');
            Route::post('deliveries/scans/node-reception', RecordNodeReceptionController::class)
                ->name('scans.node-reception');

            // Recepción conciliada en nodo (CD→CD, CD→tienda, tienda→tienda, tienda→CD): se abre con los
            // bultos esperados, se escanea cada bulto recibido, y se concilia (faltantes/sobrantes). Todo
            // idempotente y offline-first. El evento de conciliación alimenta tracking y el conector SAP.
            Route::post('node-receptions', OpenNodeReceptionController::class)
                ->name('node-receptions.open');
            Route::get('node-receptions/{shipmentId}/{nodeId}', GetNodeReceptionController::class)
                ->name('node-receptions.show');
            Route::post('node-receptions/units', ReceiveUnitAtNodeController::class)
                ->name('node-receptions.units.receive');
            Route::post('node-receptions/reconcile', ReconcileNodeReceptionController::class)
                ->name('node-receptions.reconcile');

            // Contraverificación del servidor de la firma de una etiqueta QR (verificación offline).
            Route::post('labels/verify', VerifyLabelController::class)
                ->name('labels.verify');

            // Desenlace de la entrega.
            Route::post('deliveries/proof-of-delivery', RecordProofOfDeliveryController::class)
                ->name('proof-of-delivery');
            Route::post('deliveries/failed', RecordFailedDeliveryController::class)
                ->name('failed');

            // Subida de fotos de evidencia (devuelve la URL para el POD).
            Route::post('deliveries/photos', UploadDeliveryPhotoController::class)
                ->name('photos.store');
        });
    },
];
