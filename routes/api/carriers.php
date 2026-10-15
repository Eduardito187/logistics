<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Carriers\Presentation\Http\Controller\ApproveSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\AssignCarrierForVolumeController;
use Logistics\Carriers\Presentation\Http\Controller\AttachSettlementDocumentController;
use Logistics\Carriers\Presentation\Http\Controller\CheapestCarrierForCorridorController;
use Logistics\Carriers\Presentation\Http\Controller\DisputeSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\GetSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\GetTariffForCorridorController;
use Logistics\Carriers\Presentation\Http\Controller\ListCarriersController;
use Logistics\Carriers\Presentation\Http\Controller\ListSettlementsController;
use Logistics\Carriers\Presentation\Http\Controller\MarkSettlementPaidController;
use Logistics\Carriers\Presentation\Http\Controller\RateCarrierController;
use Logistics\Carriers\Presentation\Http\Controller\ReactivateCarrierController;
use Logistics\Carriers\Presentation\Http\Controller\RegisterCarrierController;
use Logistics\Carriers\Presentation\Http\Controller\SetTariffController;
use Logistics\Carriers\Presentation\Http\Controller\SubmitSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\SuspendCarrierController;

/*
| Fragmento de rutas del contexto Transportistas (carriers).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware + rate limit los pone routes/api.php). Rutas relativas a la puerta.
*/

return [
    // Puerta integrations: alta/gestión de transportistas, tarifas, calificación y liquidaciones
    // (sistemas de transportistas y SAP, con credenciales de servicio).
    'integrations' => static function (): void {
        Route::prefix('carriers')->name('carriers.')->group(function (): void {
            // Alta de un transportista tercero.
            Route::post('/', RegisterCarrierController::class)->name('register');

            // Suspender / reactivar un transportista (deja de / vuelve a recibir asignaciones).
            Route::post('{carrierId}/suspend', SuspendCarrierController::class)->name('suspend');
            Route::post('{carrierId}/reactivate', ReactivateCarrierController::class)->name('reactivate');

            // Definir/actualizar la tarifa para un corredor y clase de vehículo.
            Route::post('{carrierId}/tariffs', SetTariffController::class)->name('tariffs.set');

            // Registrar una calificación de desempeño (1 a 5).
            Route::post('{carrierId}/ratings', RateCarrierController::class)->name('ratings.store');

            // El transportista envía una liquidación por un corredor y período.
            Route::post('{carrierId}/settlements', SubmitSettlementController::class)->name('settlements.submit');

            // Adjuntar un documento (factura, comprobante) a una liquidación.
            Route::post('settlements/{settlementId}/documents', AttachSettlementDocumentController::class)
                ->name('settlements.documents.attach');
        });
    },

    // Puerta app: lecturas y gestión interna (planning / torre de control / finanzas).
    'app' => static function (): void {
        Route::prefix('carriers')->name('carriers.')->group(function (): void {
            // Listado de transportistas, con filtro opcional ?status=active|suspended.
            Route::get('/', ListCarriersController::class)->name('index');

            // Tarifa de un transportista para un corredor y clase de vehículo.
            Route::get('{carrierId}/tariff', GetTariffForCorridorController::class)->name('tariff.show');

            // Tarifa más barata disponible para un corredor (consolidación por costo).
            Route::get('tariffs/cheapest', CheapestCarrierForCorridorController::class)->name('tariffs.cheapest');

            // Asignación de un corredor por volumen: clase de vehículo + tarifa más barata de esa clase.
            Route::get('assignment', AssignCarrierForVolumeController::class)->name('assignment');

            // Liquidaciones: listado (filtros ?carrier_id= y ?status=) y detalle.
            Route::get('settlements', ListSettlementsController::class)->name('settlements.index');
            Route::get('settlements/{settlementId}', GetSettlementController::class)->name('settlements.show');

            // Revisión de liquidaciones: aprobar, pagar, objetar.
            Route::post('settlements/{settlementId}/approve', ApproveSettlementController::class)
                ->name('settlements.approve');
            Route::post('settlements/{settlementId}/pay', MarkSettlementPaidController::class)
                ->name('settlements.pay');
            Route::post('settlements/{settlementId}/dispute', DisputeSettlementController::class)
                ->name('settlements.dispute');
        });
    },
];
