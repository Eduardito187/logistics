<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\FleetOps\Presentation\Http\Controller\ActivatePlanController;
use Logistics\FleetOps\Presentation\Http\Controller\CancelMaintenanceController;
use Logistics\FleetOps\Presentation\Http\Controller\CloseDepartureController;
use Logistics\FleetOps\Presentation\Http\Controller\CompleteMaintenanceController;
use Logistics\FleetOps\Presentation\Http\Controller\DeactivatePlanController;
use Logistics\FleetOps\Presentation\Http\Controller\DefineMaintenancePlanController;
use Logistics\FleetOps\Presentation\Http\Controller\DefineVehicleProfileController;
use Logistics\FleetOps\Presentation\Http\Controller\GetCurrentFuelPriceController;
use Logistics\FleetOps\Presentation\Http\Controller\GetDailyFuelSpendController;
use Logistics\FleetOps\Presentation\Http\Controller\GetDepartureController;
use Logistics\FleetOps\Presentation\Http\Controller\GetFuelConsumptionController;
use Logistics\FleetOps\Presentation\Http\Controller\GetMaintenanceCostController;
use Logistics\FleetOps\Presentation\Http\Controller\GetMaintenanceOrderController;
use Logistics\FleetOps\Presentation\Http\Controller\GetVehicleCostSummaryController;
use Logistics\FleetOps\Presentation\Http\Controller\GetVehicleExpenseTotalController;
use Logistics\FleetOps\Presentation\Http\Controller\GetVehicleProfileController;
use Logistics\FleetOps\Presentation\Http\Controller\GetVehicleUtilizationController;
use Logistics\FleetOps\Presentation\Http\Controller\ListDeparturesController;
use Logistics\FleetOps\Presentation\Http\Controller\ListDueMaintenanceController;
use Logistics\FleetOps\Presentation\Http\Controller\ListExpensesController;
use Logistics\FleetOps\Presentation\Http\Controller\ListFuelPricesController;
use Logistics\FleetOps\Presentation\Http\Controller\ListMaintenanceOrdersController;
use Logistics\FleetOps\Presentation\Http\Controller\ListMaintenancePlansController;
use Logistics\FleetOps\Presentation\Http\Controller\ListOdometerReadingsController;
use Logistics\FleetOps\Presentation\Http\Controller\ListRefuelsController;
use Logistics\FleetOps\Presentation\Http\Controller\ListVehicleProfilesController;
use Logistics\FleetOps\Presentation\Http\Controller\RecordExpenseController;
use Logistics\FleetOps\Presentation\Http\Controller\RecordOdometerController;
use Logistics\FleetOps\Presentation\Http\Controller\RecordRefuelController;
use Logistics\FleetOps\Presentation\Http\Controller\RecordServiceDoneController;
use Logistics\FleetOps\Presentation\Http\Controller\RegisterDepartureController;
use Logistics\FleetOps\Presentation\Http\Controller\ScheduleMaintenanceController;
use Logistics\FleetOps\Presentation\Http\Controller\SetFuelPriceController;
use Logistics\FleetOps\Presentation\Http\Controller\StartMaintenanceController;
use Logistics\FleetOps\Presentation\Http\Controller\UpdateVehicleOdometerController;

/*
| Fragmento de rutas del contexto Operaciones de flota (fleetops).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware + rate limit los pone routes/api.php). Rutas relativas a la puerta.
*/

return [
    // Puerta app: gestión interna (taller/flota/finanzas) y todas las lecturas de costos y operación.
    'app' => static function (): void {
        Route::prefix('fleetops')->name('fleetops.')->group(function (): void {
            // Precios de combustible: listado, precio vigente y alta de un precio.
            Route::get('fuel-prices', ListFuelPricesController::class)->name('fuel-prices.index');
            Route::get('fuel-prices/current', GetCurrentFuelPriceController::class)->name('fuel-prices.current');
            Route::post('fuel-prices', SetFuelPriceController::class)->name('fuel-prices.set');

            // Cargas de combustible: listado, alta y métricas (consumo por vehículo, gasto diario).
            Route::get('refuels', ListRefuelsController::class)->name('refuels.index');
            Route::post('refuels', RecordRefuelController::class)->name('refuels.store');
            Route::get('fuel/consumption', GetFuelConsumptionController::class)->name('fuel.consumption');
            Route::get('fuel/daily-spend', GetDailyFuelSpendController::class)->name('fuel.daily-spend');

            // Órdenes de mantenimiento: listado, detalle, agendar y transiciones (start/complete/cancel).
            Route::get('maintenance/orders', ListMaintenanceOrdersController::class)->name('maintenance.orders.index');
            Route::post('maintenance/orders', ScheduleMaintenanceController::class)->name('maintenance.orders.schedule');
            Route::get('maintenance/orders/{orderId}', GetMaintenanceOrderController::class)->name('maintenance.orders.show');
            Route::post('maintenance/orders/{orderId}/start', StartMaintenanceController::class)->name('maintenance.orders.start');
            Route::post('maintenance/orders/{orderId}/complete', CompleteMaintenanceController::class)->name('maintenance.orders.complete');
            Route::post('maintenance/orders/{orderId}/cancel', CancelMaintenanceController::class)->name('maintenance.orders.cancel');

            // Planes de mantenimiento preventivo: listado, definir y acciones (servicio hecho/activar/desactivar).
            Route::get('maintenance/plans', ListMaintenancePlansController::class)->name('maintenance.plans.index');
            Route::post('maintenance/plans', DefineMaintenancePlanController::class)->name('maintenance.plans.define');
            Route::post('maintenance/plans/{planId}/service-done', RecordServiceDoneController::class)->name('maintenance.plans.service-done');
            Route::post('maintenance/plans/{planId}/activate', ActivatePlanController::class)->name('maintenance.plans.activate');
            Route::post('maintenance/plans/{planId}/deactivate', DeactivatePlanController::class)->name('maintenance.plans.deactivate');

            // Costo de mantenimiento por rango y planes vencidos/por vencer.
            Route::get('maintenance/cost', GetMaintenanceCostController::class)->name('maintenance.cost');
            Route::get('maintenance/due', ListDueMaintenanceController::class)->name('maintenance.due');

            // Salidas/despachos: listado, detalle, registrar y cerrar (odómetro de regreso).
            Route::get('departures', ListDeparturesController::class)->name('departures.index');
            Route::post('departures', RegisterDepartureController::class)->name('departures.register');
            Route::get('departures/{departureId}', GetDepartureController::class)->name('departures.show');
            Route::post('departures/{departureId}/close', CloseDepartureController::class)->name('departures.close');

            // Odómetro: listado de lecturas y alta manual; utilización por vehículo y rango.
            Route::get('odometer', ListOdometerReadingsController::class)->name('odometer.index');
            Route::post('odometer', RecordOdometerController::class)->name('odometer.store');
            Route::get('utilization', GetVehicleUtilizationController::class)->name('utilization');

            // Perfiles técnicos (1:1 por vehículo): listado, detalle, upsert y fijar odómetro vigente.
            Route::get('vehicle-profiles', ListVehicleProfilesController::class)->name('vehicle-profiles.index');
            Route::post('vehicle-profiles', DefineVehicleProfileController::class)->name('vehicle-profiles.define');
            Route::get('vehicle-profiles/{vehicleId}', GetVehicleProfileController::class)->name('vehicle-profiles.show');
            Route::post('vehicle-profiles/{vehicleId}/odometer', UpdateVehicleOdometerController::class)->name('vehicle-profiles.odometer');

            // Gastos de flota: listado, alta y total por vehículo.
            Route::get('expenses', ListExpensesController::class)->name('expenses.index');
            Route::post('expenses', RecordExpenseController::class)->name('expenses.store');
            Route::get('expenses/total', GetVehicleExpenseTotalController::class)->name('expenses.total');

            // Resumen de costos (TCO + costo por km) de un vehículo entre dos fechas.
            Route::get('cost-summary', GetVehicleCostSummaryController::class)->name('cost-summary');
        });
    },

    // Puerta integrations: ingesta desde sistemas externos con credenciales de servicio.
    // Cargas de combustible desde sistemas de tarjeta/estación y lecturas de odómetro desde telemetría.
    'integrations' => static function (): void {
        Route::prefix('fleetops')->name('fleetops.')->group(function (): void {
            Route::post('refuels', RecordRefuelController::class)->name('refuels.store');
            Route::post('odometer', RecordOdometerController::class)->name('odometer.store');
        });
    },
];
