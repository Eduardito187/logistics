<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Carriers\Presentation\Http\Controller\ApproveSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\AssignCarrierForVolumeController;
use Logistics\Carriers\Presentation\Http\Controller\AttachSettlementDocumentController;
use Logistics\Carriers\Presentation\Http\Controller\CheapestCarrierForCorridorController;
use Logistics\Carriers\Presentation\Http\Controller\CloseCarrierTripController;
use Logistics\Carriers\Presentation\Http\Controller\DisputeSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\GetSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\GetTariffForCorridorController;
use Logistics\Carriers\Presentation\Http\Controller\ListCarrierAssignmentsController;
use Logistics\Carriers\Presentation\Http\Controller\ListCarrierCorridorsController;
use Logistics\Carriers\Presentation\Http\Controller\ListCarriersController;
use Logistics\Carriers\Presentation\Http\Controller\ListSettlementsController;
use Logistics\Carriers\Presentation\Http\Controller\MarkSettlementPaidController;
use Logistics\Carriers\Presentation\Http\Controller\RateCarrierController;
use Logistics\Carriers\Presentation\Http\Controller\ReactivateCarrierController;
use Logistics\Carriers\Presentation\Http\Controller\RegisterCarrierController;
use Logistics\Carriers\Presentation\Http\Controller\SetTariffController;
use Logistics\Carriers\Presentation\Http\Controller\StartCarrierTripController;
use Logistics\Carriers\Presentation\Http\Controller\SubmitSettlementController;
use Logistics\Carriers\Presentation\Http\Controller\SuspendCarrierController;
use Logistics\Carriers\Presentation\Http\Middleware\EnsureCarrierScope;

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
    //
    // Autorización server-side por EnsureCarrierScope (nunca se confía en un carrier_id del cliente):
    //   - oficina (admin/supervisor): cross-transportista;
    //   - transportista: acotado a users.carrier_id (sin vínculo → 403);
    //   - otros roles: 403.
    // Aprobar y marcar pagada son acciones de OFICINA (segregación de funciones: el transportista no
    // aprueba ni da por pagada su propia factura; ver docblock del agregado Settlement). El transportista
    // solo lee y objeta SUS liquidaciones.
    'app' => static function (): void {
        $scope = static fn (string $mode): string => EnsureCarrierScope::class.':'.$mode;

        Route::prefix('carriers')->name('carriers.')->group(function () use ($scope): void {
            // Listado de transportistas, con filtro opcional ?status=active|suspended.
            // El transportista solo se ve a sí mismo.
            Route::get('/', ListCarriersController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_LIST))
                ->name('index');

            // Carga asignada (auditoría app #4): viajes con sus envíos y productos (nombre + SKU) para que el
            // transportista sepa qué recoger. Transportista: solo los suyos; oficina: todos (?carrier_id=).
            // Se declara ANTES de `{carrierId}/...` para que `me` nunca se lea como id.
            Route::get('me/assignments', ListCarrierAssignmentsController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_LIST))
                ->name('assignments.index');

            // Corredores tarifados (selector de la app): distintos, con clases de vehículo y etiquetas legibles.
            // `from`/`to` son los slugs que acepta `{carrierId}/tariff`. Transportista: solo los de su
            // transportista (se ignora ?carrier_id=); oficina: todos, con ?carrier_id= opcional.
            Route::get('corridors', ListCarrierCorridorsController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_LIST))
                ->name('corridors.index');

            // Iniciar / cerrar un viaje (idempotentes): oficina o el transportista dueño del viaje.
            Route::post('trips/{tripId}/start', StartCarrierTripController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_TRIP))
                ->name('trips.start');
            Route::post('trips/{tripId}/close', CloseCarrierTripController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_TRIP))
                ->name('trips.close');

            // Tarifa de un transportista para un corredor y clase de vehículo (el transportista: solo la suya).
            Route::get('{carrierId}/tariff', GetTariffForCorridorController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_CARRIER))
                ->name('tariff.show');

            // Tarifa más barata disponible para un corredor (consolidación por costo). Compara tarifas de
            // TODOS los transportistas: herramienta de planificación, solo oficina.
            Route::get('tariffs/cheapest', CheapestCarrierForCorridorController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_OFFICE))
                ->name('tariffs.cheapest');

            // Asignación de un corredor por volumen: clase de vehículo + tarifa más barata de esa clase.
            // Mismo motivo: solo oficina.
            Route::get('assignment', AssignCarrierForVolumeController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_OFFICE))
                ->name('assignment');

            // Liquidaciones: listado (filtros ?carrier_id=, ?status= y ?period_from= / ?period_to= Y-m-d
            // inclusivos; el transportista recibe SIEMPRE solo las suyas, se ignora su ?carrier_id=) y
            // detalle (403 si es ajena).
            Route::get('settlements', ListSettlementsController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_LIST))
                ->name('settlements.index');
            Route::get('settlements/{settlementId}', GetSettlementController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_SETTLEMENT))
                ->name('settlements.show');

            // Revisión de liquidaciones: aprobar y pagar (solo oficina), objetar (oficina o el propio
            // transportista sobre SU liquidación).
            Route::post('settlements/{settlementId}/approve', ApproveSettlementController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_OFFICE))
                ->name('settlements.approve');
            Route::post('settlements/{settlementId}/pay', MarkSettlementPaidController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_OFFICE))
                ->name('settlements.pay');
            Route::post('settlements/{settlementId}/dispute', DisputeSettlementController::class)
                ->middleware($scope(EnsureCarrierScope::MODE_SETTLEMENT))
                ->name('settlements.dispute');
        });
    },
];
