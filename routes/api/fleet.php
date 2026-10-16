<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Fleet\Presentation\Http\Controller\CrewController;
use Logistics\Fleet\Presentation\Http\Controller\DocumentController;
use Logistics\Fleet\Presentation\Http\Controller\DriverController;
use Logistics\Fleet\Presentation\Http\Controller\ExpiringDocumentController;
use Logistics\Fleet\Presentation\Http\Controller\HelperController;
use Logistics\Fleet\Presentation\Http\Controller\MyCrewController;
use Logistics\Fleet\Presentation\Http\Controller\PreTripChecklistController;
use Logistics\Fleet\Presentation\Http\Controller\VehicleController;

/*
| Fragmento de rutas del bounded context Fleet (flota y tripulación).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta.
|
| Fleet no expone endpoints a la puerta channels: la flota es información interna.
*/

return [
    // Puerta app — supervisor de patio / operación de flota (app interna Flutter).
    'app' => static function (): void {
        Route::prefix('fleet')->name('fleet.')->group(function (): void {
            // Lecturas operativas de la flota.
            Route::get('vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
            Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
            Route::get('helpers', [HelperController::class, 'index'])->name('helpers.index');
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::get('pre-trip-checklists', [PreTripChecklistController::class, 'index'])
                ->name('pre-trip-checklists.index');
            Route::get('expiring-documents', [ExpiringDocumentController::class, 'index'])
                ->name('expiring-documents.index');

            // Asignación del día del chofer AUTENTICADO: su vehículo y tripulación (no elige, se los asignan).
            Route::get('my-crew', [MyCrewController::class, 'show'])->name('my-crew.show');

            // Habilitar / inhabilitar / mandar a mantenimiento un vehículo.
            Route::patch('vehicles/{vehicleId}/status', [VehicleController::class, 'changeStatus'])
                ->name('vehicles.change-status');

            // Activar / dar de baja a un ayudante.
            Route::patch('helpers/{helperId}/availability', [HelperController::class, 'setAvailability'])
                ->name('helpers.set-availability');

            // Armar la tripulación del día (chofer + ayudantes + vehículo).
            Route::post('crews', [CrewController::class, 'store'])->name('crews.store');

            // Registrar el checklist pre-salida ejecutado por el chofer antes de salir a ruta.
            Route::post('pre-trip-checklists', [PreTripChecklistController::class, 'store'])
                ->name('pre-trip-checklists.store');
        });
    },

    // Puerta integrations — datos maestros desde SAP / RR. HH. (credenciales de servicio).
    'integrations' => static function (): void {
        Route::prefix('fleet')->name('fleet.')->group(function (): void {
            Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
            Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
            Route::post('helpers', [HelperController::class, 'store'])->name('helpers.store');
            Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        });
    },
];
