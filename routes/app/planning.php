<?php

declare(strict_types=1);

use App\Http\Controllers\App\Planning\PlanningController;
use Illuminate\Support\Facades\Route;

/*
| Módulo Planificación (tablero de planificación) de la app web (Inertia). Se incluye dentro del grupo
| de routes/web.php (auth + prefix 'app' + name 'app.'). Solo lectura: lista los planes de reparto y
| muestra el detalle de un plan con sus paradas en orden y el mapa. Las escrituras (asignar, reordenar,
| publicar) siguen en el admin/API; aquí no hay persistencia.
*/

Route::prefix('planning')->name('planning.')->group(function (): void {
    Route::get('/', [PlanningController::class, 'index'])->name('index');
    Route::get('/{plan}', [PlanningController::class, 'show'])->name('show');
});
