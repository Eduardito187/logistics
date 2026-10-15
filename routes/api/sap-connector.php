<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\SapConnector\Presentation\Http\Controller\AcknowledgeStockTransferController;
use Logistics\SapConnector\Presentation\Http\Controller\EnqueueStockTransferController;
use Logistics\SapConnector\Presentation\Http\Controller\ImportSapOrdersController;
use Logistics\SapConnector\Presentation\Http\Controller\MaterialMappingController;
use Logistics\SapConnector\Presentation\Http\Controller\StockTransferReconciliationController;

/*
 * Fragmento de rutas del bounded context sap-connector (capa anti-corrupción SAP, ADR 002).
 *
 * Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
 * (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta.
 */

return [
    // Puerta integrations — credenciales de servicio (SAP). Recepción de pedidos desde SAP.
    'integrations' => static function (): void {
        Route::prefix('sap')->name('sap.')->group(static function (): void {
            // Lee los pedidos pendientes en SAP y los devuelve traducidos al modelo propio.
            Route::post('orders/import', ImportSapOrdersController::class)->name('orders.import');

            // Callback de SAP: confirma un traspaso ya empujado (idempotente por event_id).
            Route::post('stock-transfers/acknowledge', AcknowledgeStockTransferController::class)
                ->name('stock-transfers.acknowledge');
        });
    },

    // Puerta app — token de usuario + dispositivo y roles (operaciones internas).
    'app' => static function (): void {
        Route::prefix('sap')->name('sap.')->group(static function (): void {
            // Encola un traspaso de stock al recibir un bulto en un nodo (idempotente por event_id).
            Route::post('stock-transfers', EnqueueStockTransferController::class)
                ->name('stock-transfers.enqueue');

            // Reconciliación: pendientes (Pending + Failed), fallidos y disparo manual de despacho.
            Route::get('stock-transfers/pending', [StockTransferReconciliationController::class, 'pending'])
                ->name('stock-transfers.pending');
            Route::get('stock-transfers/failed', [StockTransferReconciliationController::class, 'failed'])
                ->name('stock-transfers.failed');
            Route::post('stock-transfers/dispatch', [StockTransferReconciliationController::class, 'dispatch'])
                ->name('stock-transfers.dispatch');

            // Mapa de traducción sku propio ↔ coordenada SAP (configuración de la ACL, P2-SAP-06).
            Route::get('material-mappings', [MaterialMappingController::class, 'index'])
                ->name('material-mappings.index');
            Route::post('material-mappings', [MaterialMappingController::class, 'store'])
                ->name('material-mappings.store');
        });
    },
];
