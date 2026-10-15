<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Coverage\Presentation\Http\Controller\ImportCoverageKmlController;
use Logistics\Coverage\Presentation\Http\Controller\ImportCoverageMapController;
use Logistics\Coverage\Presentation\Http\Controller\ListZonesController;
use Logistics\Coverage\Presentation\Http\Controller\ResolveZoneController;

/*
| Fragmento de rutas del contexto Cobertura (coverage).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta.
|
| Contrato en openapi/paths/coverage.yaml.
*/

return [
    // Puerta app: resolución punto→zona para validar direcciones y mostrar servicios al operador.
    'app' => static function (): void {
        Route::prefix('coverage')->name('coverage.')->group(function (): void {
            Route::get('zones/resolve', ResolveZoneController::class)->name('zones.resolve');
            Route::get('zones', ListZonesController::class)->name('zones.index');
        });
    },

    // Puerta integrations: sincronización del mapa de cobertura e importación masiva desde externos.
    'integrations' => static function (): void {
        Route::prefix('coverage')->name('coverage.')->group(function (): void {
            Route::get('zones/resolve', ResolveZoneController::class)->name('zones.resolve');
            Route::get('zones', ListZonesController::class)->name('zones.index');
            Route::post('map:import', ImportCoverageMapController::class)->name('map.import');
            Route::post('map:import-kml', ImportCoverageKmlController::class)->name('map.import-kml');
        });
    },
];
