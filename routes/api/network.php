<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Network\Presentation\Http\Controller\DeactivateNodeController;
use Logistics\Network\Presentation\Http\Controller\DefineLegController;
use Logistics\Network\Presentation\Http\Controller\IndexLegController;
use Logistics\Network\Presentation\Http\Controller\IndexNodeController;
use Logistics\Network\Presentation\Http\Controller\RegisterNodeController;
use Logistics\Network\Presentation\Http\Controller\RemoveLegController;
use Logistics\Network\Presentation\Http\Controller\ShowLegController;
use Logistics\Network\Presentation\Http\Controller\ShowNodeController;

/*
| Fragmento de rutas del contexto network (nodos y tramos).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta.
*/

return [
    // Puerta app — lectura de la red para la app interna Flutter.
    'app' => static function (): void {
        Route::get('network/nodes', IndexNodeController::class)->name('network.nodes.index');
        Route::get('network/nodes/{node}', ShowNodeController::class)->name('network.nodes.show');
        Route::get('network/legs', IndexLegController::class)->name('network.legs.index');
        Route::get('network/legs/{leg}', ShowLegController::class)->name('network.legs.show');
    },

    // Puerta integrations — datos maestros desde SAP (credenciales de servicio).
    'integrations' => static function (): void {
        Route::post('network/nodes', RegisterNodeController::class)->name('network.nodes.register');
        Route::delete('network/nodes/{node}', DeactivateNodeController::class)->name('network.nodes.deactivate');
        Route::post('network/legs', DefineLegController::class)->name('network.legs.define');
        Route::delete('network/legs/{leg}', RemoveLegController::class)->name('network.legs.remove');
    },
];
