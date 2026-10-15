<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Telemetry\Presentation\Http\Controller\AcknowledgeAlertController;
use Logistics\Telemetry\Presentation\Http\Controller\ActivateGeofenceController;
use Logistics\Telemetry\Presentation\Http\Controller\DeactivateGeofenceController;
use Logistics\Telemetry\Presentation\Http\Controller\DefineGeofenceController;
use Logistics\Telemetry\Presentation\Http\Controller\DeleteGeofenceController;
use Logistics\Telemetry\Presentation\Http\Controller\GetLiveFleetPositionsController;
use Logistics\Telemetry\Presentation\Http\Controller\GetStreetTrafficController;
use Logistics\Telemetry\Presentation\Http\Controller\GetVehicleTelemetrySummaryController;
use Logistics\Telemetry\Presentation\Http\Controller\GetVehicleTrackController;
use Logistics\Telemetry\Presentation\Http\Controller\ListAlertsController;
use Logistics\Telemetry\Presentation\Http\Controller\ListGeofencesController;
use Logistics\Telemetry\Presentation\Http\Controller\ListTripsController;
use Logistics\Telemetry\Presentation\Http\Controller\RecordAppVehiclePingsController;
use Logistics\Telemetry\Presentation\Http\Controller\RecordIntegrationVehiclePingsController;
use Logistics\Telemetry\Presentation\Http\Controller\RedrawGeofenceController;
use Logistics\Telemetry\Presentation\Http\Controller\RenameGeofenceController;

/*
| Fragmento de rutas del contexto Telemetría (telemetry).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware + rate limit los pone routes/api.php). Rutas relativas a la puerta.
*/

return [
    // Puerta app: ingesta del app interno + todas las lecturas y la gestión de geocercas
    // (torre de control / planning).
    'app' => static function (): void {
        Route::prefix('telemetry')->name('telemetry.')->group(function (): void {
            // Ingesta de un lote de lecturas GPS desde el app interno (origen 'app').
            Route::post('pings', RecordAppVehiclePingsController::class)->name('pings.record');

            // Posición en vivo de toda la flota.
            Route::get('fleet/live', GetLiveFleetPositionsController::class)->name('fleet.live');

            // Traza (?from&to) y resumen diario (?date) de un vehículo.
            Route::get('vehicles/{vehicleId}/track', GetVehicleTrackController::class)->name('vehicles.track');
            Route::get('vehicles/{vehicleId}/summary', GetVehicleTelemetrySummaryController::class)->name('vehicles.summary');

            // Trips (?vehicle_id&date).
            Route::get('trips', ListTripsController::class)->name('trips.index');

            // Alertas (?vehicle_id&type&acknowledged) y su reconocimiento.
            Route::get('alerts', ListAlertsController::class)->name('alerts.index');
            Route::post('alerts/{alertId}/acknowledge', AcknowledgeAlertController::class)->name('alerts.acknowledge');

            // Geocercas: listar, crear, renombrar, redibujar, activar, desactivar y borrar.
            Route::get('geofences', ListGeofencesController::class)->name('geofences.index');
            Route::post('geofences', DefineGeofenceController::class)->name('geofences.store');
            Route::post('geofences/{geofenceId}/rename', RenameGeofenceController::class)->name('geofences.rename');
            Route::post('geofences/{geofenceId}/redraw', RedrawGeofenceController::class)->name('geofences.redraw');
            Route::post('geofences/{geofenceId}/activate', ActivateGeofenceController::class)->name('geofences.activate');
            Route::post('geofences/{geofenceId}/deactivate', DeactivateGeofenceController::class)->name('geofences.deactivate');
            Route::delete('geofences/{geofenceId}', DeleteGeofenceController::class)->name('geofences.delete');

            // Tráfico agregado de calles (?bbox) para el mapa de congestión.
            Route::get('streets', GetStreetTrafficController::class)->name('streets.index');
        });
    },

    // Puerta integrations: ingesta de pings desde proveedores GPS externos (origen 'integration').
    'integrations' => static function (): void {
        Route::prefix('telemetry')->name('telemetry.')->group(function (): void {
            Route::post('pings', RecordIntegrationVehiclePingsController::class)->name('pings.record');
        });
    },
];
