<?php

declare(strict_types=1);

use App\Http\Controllers\App\Planning\PlanningBoardController;
use Illuminate\Support\Facades\Route;

/*
| Tablero del DÍA de Planificación (arrastrar pedidos entre camiones) de la app web (Inertia). Se
| auto-incluye dentro del grupo de routes/web.php (auth + prefix 'app' + name 'app.'). A diferencia de
| planning.php (solo lectura) y planning-board.php (reordenar DENTRO de un plan), aquí se pintan todos los
| planes de una fecha como columnas por camión y se mueve un envío de un camión a OTRO (comando
| ReassignShipmentToVehicle). Este archivo NO edita planning.php ni planning-board.php.
*/

Route::prefix('planning')->name('planning.')->group(function (): void {
    // Tablero del día: todas las columnas (camiones) de una fecha.
    Route::get('plans/day/{date}', [PlanningBoardController::class, 'day'])->name('day');

    // Mover un envío de un plan a otro del mismo día (arrastre entre columnas).
    Route::post('plans/reassign', [PlanningBoardController::class, 'reassign'])->name('reassign');
});
