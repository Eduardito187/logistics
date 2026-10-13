<?php

declare(strict_types=1);

use App\Http\Controllers\App\Tracking\TrackingController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Seguimiento (tracking) de la app web operativa (Inertia). Se incluye dentro del grupo de
| routes/web.php (auth + prefix 'app' + name 'app.'). Solo lectura: lista los seguimientos de envíos y
| muestra el detalle con estado visible, ETA, última posición GPS y mapa. Patrón: módulo Operaciones.
*/

Route::prefix('tracking')->name('tracking.')->group(function (): void {
    Route::get('/', [TrackingController::class, 'index'])->name('index');
    Route::get('/{tracking}', [TrackingController::class, 'show'])->name('show');
});
