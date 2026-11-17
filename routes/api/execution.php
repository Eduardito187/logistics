<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Execution\Presentation\Http\AssembleDailyRouteController;
use Logistics\Execution\Presentation\Http\AssignOrderVehicleController;
use Logistics\Execution\Presentation\Http\AssignShelfLocationController;
use Logistics\Execution\Presentation\Http\CaptureDeliveryEvidenceController;
use Logistics\Execution\Presentation\Http\CloseTransferWithShortageController;
use Logistics\Execution\Presentation\Http\CreateFailureReasonController;
use Logistics\Execution\Presentation\Http\DeactivateFailureReasonController;
use Logistics\Execution\Presentation\Http\DriverRouteLifecycleController;
use Logistics\Execution\Presentation\Http\GetDeliveryEvidenceController;
use Logistics\Execution\Presentation\Http\GetDeliveryStateController;
use Logistics\Execution\Presentation\Http\GetDriverRouteController;
use Logistics\Execution\Presentation\Http\GetNodeReceptionController;
use Logistics\Execution\Presentation\Http\ListActiveFailureReasonsController;
use Logistics\Execution\Presentation\Http\ListFailureReasonsController;
use Logistics\Execution\Presentation\Http\ListNodeReceptionsController;
use Logistics\Execution\Presentation\Http\ListVehicleDeliveriesController;
use Logistics\Execution\Presentation\Http\MarkStopArrivedController;
use Logistics\Execution\Presentation\Http\OpenNodeReceptionController;
use Logistics\Execution\Presentation\Http\ReceiveUnitAtNodeController;
use Logistics\Execution\Presentation\Http\ReconcileNodeReceptionController;
use Logistics\Execution\Presentation\Http\RecordCarrierHandoffController;
use Logistics\Execution\Presentation\Http\RecordEvidenceValidationController;
use Logistics\Execution\Presentation\Http\RecordFailedDeliveryController;
use Logistics\Execution\Presentation\Http\RecordLoadScanController;
use Logistics\Execution\Presentation\Http\RecordNodeReceptionController;
use Logistics\Execution\Presentation\Http\RecordPreparationScanController;
use Logistics\Execution\Presentation\Http\RecordProofOfDeliveryController;
use Logistics\Execution\Presentation\Http\RecordScanBatchController;
use Logistics\Execution\Presentation\Http\RecordStoreDropController;
use Logistics\Execution\Presentation\Http\RecordUnloadScanController;
use Logistics\Execution\Presentation\Http\ReportDeliveryIssueController;
use Logistics\Execution\Presentation\Http\ReportVehicleIncidentController;
use Logistics\Execution\Presentation\Http\UpdateFailureReasonController;
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
            // Endpoints POR VEHÍCULO: solo oficina (admin/supervisor/planificador) o el tripulante (chofer o
            // ayudante) de ESE camión el día relevante. La hoja de ruta y las entregas traen PII del cliente
            // (teléfono, dirección, líneas), así que la pertenencia la exige el middleware `vehicle.crew`
            // server-side; el chofer resuelve su propio vehículo por «Mi ruta», así que su caso normal no se
            // rompe. Armar / abrir / cerrar la ruta y reportar un incidente quedan igual acotados: oficina o
            // el tripulante de ese camión.
            Route::middleware('vehicle.crew')->group(function (): void {
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

                // Abrir / cerrar la ruta del día (idempotentes; devuelven status/started_at/closed_at). El cierre
                // NO exige todas las paradas entregadas: las pendientes quedan y se informan (pending_stops).
                Route::post('vehicles/{vehicleId}/route/start', [DriverRouteLifecycleController::class, 'start'])
                    ->name('vehicles.route.start');
                Route::post('vehicles/{vehicleId}/route/close', [DriverRouteLifecycleController::class, 'close'])
                    ->name('vehicles.route.close');

                // Reporte de incidente del chofer (avería/accidente): dispara la reacción automática
                // (fuera de servicio + reprogramación + avisos). Idempotente por Idempotency-Key.
                Route::post('vehicles/{vehicleId}/incidents', ReportVehicleIncidentController::class)
                    ->name('vehicles.incidents.report');
            });
            // Asignar el pedido a un camión de reparto real y atarlo a la ruta de ese vehículo (andén):
            // aparece en «Mi ruta de hoy» del chofer. 422 si el vehículo no existe/no está operativo.
            Route::post('orders/{orderNumber}/vehicle', AssignOrderVehicleController::class)
                ->middleware('order.scope')
                ->name('orders.vehicle.assign');

            // Estado actual de la entrega de un envío (con sus escaneos).
            Route::get('deliveries/{shipmentId}', GetDeliveryStateController::class)
                ->name('deliveries.show');

            // Escaneos de terreno (idempotentes, offline-first).
            Route::post('deliveries/scans/preparation', RecordPreparationScanController::class)
                ->middleware('order.scope')
                ->name('scans.preparation');
            Route::post('deliveries/scans/load', RecordLoadScanController::class)
                ->middleware('order.scope')
                ->name('scans.load');
            Route::post('deliveries/scans/unload', RecordUnloadScanController::class)
                ->middleware('order.scope')
                ->name('scans.unload');
            // Lote de escaneos de la cola offline → 207 con resultado por ítem (accepted|duplicate|rejected).
            Route::post('deliveries/scans/batch', RecordScanBatchController::class)
                ->name('scans.batch');
            Route::post('deliveries/scans/node-reception', RecordNodeReceptionController::class)
                ->middleware('enforce.store.node:body.node_id')
                ->name('scans.node-reception');

            // Recepción conciliada en nodo (CD→CD, CD→tienda, tienda→tienda, tienda→CD): se abre con los
            // productos esperados, se escanea cada producto recibido, y se concilia (faltantes/sobrantes). Cada paso es
            // idempotente y offline-first. El evento de conciliación alimenta tracking y el conector SAP.
            // Abrir acepta solo {shipment_id, occurred_at}: destino/origen/esperados se derivan en el
            // servidor; por eso el alcance por tienda lo aplica el controller (no hay nodo en el cuerpo).
            Route::post('node-receptions', OpenNodeReceptionController::class)
                ->name('node-receptions.open');
            // Lista paginada por nodo y estado (?node_id=&status=open), con el resumen de cada recepción.
            // Alcance por tienda en el controller (sin node_id, la tienda ve el suyo).
            Route::get('node-receptions', ListNodeReceptionsController::class)
                ->name('node-receptions.index');
            // Ubicación en estante: un producto o un lote (unit_ids). Idempotente.
            Route::post('node-receptions/{shipmentId}/units/location', AssignShelfLocationController::class)
                ->whereUuid('shipmentId')
                ->middleware('enforce.store.node:body.node_id')
                ->name('node-receptions.units.location.batch');
            Route::post('node-receptions/{shipmentId}/units/{unitId}/location', AssignShelfLocationController::class)
                ->whereUuid('shipmentId')
                ->middleware('enforce.store.node:body.node_id')
                ->name('node-receptions.units.location');
            Route::get('node-receptions/{shipmentId}/{nodeId}', GetNodeReceptionController::class)
                ->middleware('enforce.store.node:route.nodeId')
                ->name('node-receptions.show');
            Route::post('node-receptions/units', ReceiveUnitAtNodeController::class)
                ->middleware('enforce.store.node:body.destination_node_id')
                ->name('node-receptions.units.receive');
            Route::post('node-receptions/reconcile', ReconcileNodeReceptionController::class)
                ->middleware('enforce.store.node:body.destination_node_id')
                ->name('node-receptions.reconcile');

            // Contraverificación del servidor de la firma de una etiqueta QR (verificación offline).
            Route::post('labels/verify', VerifyLabelController::class)
                ->name('labels.verify');

            // Desenlace de la entrega.
            Route::post('deliveries/proof-of-delivery', RecordProofOfDeliveryController::class)
                ->middleware('order.scope')
                ->name('proof-of-delivery');
            Route::post('deliveries/failed', RecordFailedDeliveryController::class)
                ->middleware('order.scope')
                ->name('failed');
            // Entrega EN TIENDA del chofer: deja en la tienda un pedido que el cliente retira ahí (o un
            // traspaso). Cierra su parada; el pedido queda «listo para retirar», no entregado.
            Route::post('deliveries/{shipmentId}/store-drop', RecordStoreDropController::class)
                ->whereUuid('shipmentId')
                ->middleware('order.scope')
                ->name('store-drop');
            // Handoff a la transportadora (flujo tercerizado): nuestro lado deja la carga en el almacén del
            // tercero (carrier_hub). Cierra el tramo propio y marca «entregado a la transportadora»; NO entrega.
            Route::post('deliveries/{shipmentId}/carrier-handoff', RecordCarrierHandoffController::class)
                ->whereUuid('shipmentId')
                ->middleware('order.scope')
                ->name('carrier-handoff');
            // Cierre manual de OFICINA de un traspaso que quedó en curso con faltantes (la tienda nunca
            // concilió): queda entregado con `with_shortage`. Solo admin y supervisor; motivo obligatorio.
            Route::post('deliveries/{shipmentId}/close-transfer', CloseTransferWithShortageController::class)
                ->whereUuid('shipmentId')
                ->middleware(['role:supervisor,admin', 'order.scope'])
                ->name('close-transfer');
            // Cierre de la entrega por envío (POD en lote: unit_ids, una sola firma/GPS). Mismo controller.
            Route::post('deliveries/{shipmentId}/complete', RecordProofOfDeliveryController::class)
                ->middleware('order.scope')
                ->name('complete');
            // Llegada a la parada (geocerca o manual): persiste arrived_at (la primera gana).
            Route::post('deliveries/{shipmentId}/arrived', MarkStopArrivedController::class)
                ->middleware('order.scope')
                ->name('arrived');

            // Inconveniente de una entrega EN CURSO (no la cierra): retraso, acceso, pedido del cliente,
            // dirección poco clara u otro. Idempotente por Idempotency-Key. Deja constancia y avisa por
            // el outbox. Distinto de la entrega fallida y del incidente del camión.
            Route::post('deliveries/{shipmentId}/issues', ReportDeliveryIssueController::class)
                ->middleware('order.scope')
                ->name('issues.report');

            // Subida de fotos de evidencia (devuelve la URL para el POD). Idempotente por Idempotency-Key en
            // la puerta de almacenamiento (misma clave → misma referencia, sin duplicar la foto). Se excluye
            // el middleware genérico `idempotency`: compara el cuerpo crudo y un multipart reintentado trae
            // otro boundary, lo que respondía 409 en vez de la referencia original.
            Route::post('deliveries/photos', UploadDeliveryPhotoController::class)
                ->withoutMiddleware('idempotency')
                ->name('photos.store');

            // Evidencia anti-fraude LIGADA AL CLIENTE (firma, documento, POD, daño): captura con hash de
            // integridad + auditoría, y consulta del estado de validación por envío. Idempotente.
            Route::post('deliveries/evidence', CaptureDeliveryEvidenceController::class)
                ->name('evidence.capture');
            Route::get('deliveries/{shipmentId}/evidence', GetDeliveryEvidenceController::class)
                ->name('evidence.show');

            // Catálogo CONFIGURABLE de motivos de "no se pudo entregar".
            // Lectura para el chofer: solo los motivos ACTIVOS, ordenados. Sin gating especial de rol
            // (cualquier usuario app autenticado); la app lo consume al marcar una entrega como fallida.
            Route::get('config/failure-reasons', ListActiveFailureReasonsController::class)
                ->name('config.failure-reasons.index');

            // Administración del catálogo: gateada a oficina (admin / planificador). El `code` de la ruta es
            // el slug del motivo (NUNCA el UUID). DELETE = desactivar (soft).
            Route::middleware('role:admin,planificador')->group(function (): void {
                Route::get('admin/failure-reasons', ListFailureReasonsController::class)
                    ->name('admin.failure-reasons.index');
                Route::post('admin/failure-reasons', CreateFailureReasonController::class)
                    ->name('admin.failure-reasons.store');
                Route::put('admin/failure-reasons/{code}', UpdateFailureReasonController::class)
                    ->name('admin.failure-reasons.update');
                Route::delete('admin/failure-reasons/{code}', DeactivateFailureReasonController::class)
                    ->name('admin.failure-reasons.deactivate');
            });
        });
    },

    // Puerta integrations — el validador anti-fraude asíncrono (worker/sidecar on-premise) escribe el
    // resultado de validar una evidencia (firma / documento). Credenciales de servicio las aplica el
    // integrador.
    'integrations' => static function (): void {
        Route::post('evidence/{evidenceId}/validation', RecordEvidenceValidationController::class)
            ->name('execution.evidence.validation.record');
    },
];
