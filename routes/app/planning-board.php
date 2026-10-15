<?php

declare(strict_types=1);

use App\Http\Controllers\App\Planning\PlanningController;
use App\Http\Controllers\App\Planning\PlanningWriteController;
use Illuminate\Support\Facades\Route;

/*
| Tablero interactivo de Planificación (fase 2) de la app web (Inertia). Se auto-incluye dentro del
| grupo de routes/web.php (auth + prefix 'app' + name 'app.'). A diferencia de routes/app/planning.php
| (solo lectura), aquí viven la vista del tablero y las ESCRITURAS: reordenar la secuencia por arrastre,
| publicar el plan y disparar la auto-planificación del día. Las escrituras despachan comandos por el
| CommandBus (ver PlanningWriteController). Este archivo NO edita planning.php.
*/

Route::prefix('planning')->name('planning.')->group(function (): void {
    // Vista del tablero interactivo de un plan.
    Route::get('/{plan}/board', [PlanningController::class, 'board'])->name('board');

    // Reordenar por arrastre la secuencia de paradas (comando ReorderStops).
    Route::put('plans/{plan}/sequence', [PlanningWriteController::class, 'sequence'])->name('sequence');

    // Publicar el plan (comando PublishPlan).
    Route::post('plans/{plan}/publish', [PlanningWriteController::class, 'publish'])->name('publish');

    // Disparar la auto-planificación del día (comando GenerateDailyPlan).
    Route::post('plans/auto', [PlanningWriteController::class, 'auto'])->name('auto');
});
