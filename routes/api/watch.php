<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\App\WatchSummaryController;
use Illuminate\Support\Facades\Route;

/*
| Fragmento de rutas del resumen para RELOJ (smartwatch standalone).
|
| Devuelve un mapa puerta => closure; el integrador (routes/api.php) lo invoca dentro del grupo de la puerta
| app (prefijo /app + middleware de la app). Es una agregación de solo lectura (ruta + tripulación + bandeja)
| para una muñeca sin el teléfono vivo; el payload es chico a propósito.
*/

return [
    'app' => static function (): void {
        Route::get('watch/summary', WatchSummaryController::class)->name('watch.summary');
    },
];
