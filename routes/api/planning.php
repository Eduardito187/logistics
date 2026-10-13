<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Planning\Presentation\Http\Controller\AutoPlanController;
use Logistics\Planning\Presentation\Http\Controller\PlanController;

/*
| Fragmento de rutas del contexto Planificación.
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta.
| Todos los endpoints pertenecen a la puerta app (app interna Flutter, rol planificador). Rutas
| relativas a la puerta.
*/

return [
    // Puerta app — planificación del día (app interna Flutter, rol planificador).
    'app' => static function (): void {
        Route::prefix('plans')->name('planning.plans.')->group(function (): void {
            // Ver el plan del día (uno por camión): GET /plans?date=YYYY-MM-DD
            Route::get('/', [PlanController::class, 'index'])->name('index');

            // Generar la propuesta automática pedido→camión de un día.
            Route::post('/', [PlanController::class, 'store'])->name('store');

            // Auto-planificador del día (asíncrono): dispara la generación y consulta el resultado.
            // POST /plans/auto {plan_date, strategy, params?} → encola la corrida (202).
            Route::post('/auto', [AutoPlanController::class, 'store'])->name('auto.store');
            // GET /plans/auto?date=YYYY-MM-DD&strategy=optimization|slots → estado/resultado.
            Route::get('/auto', [AutoPlanController::class, 'show'])->name('auto.show');

            // Ver un plan con su secuencia, ETA y plan de carga.
            Route::get('/{planId}', [PlanController::class, 'show'])->name('show')->whereUuid('planId');

            // ETA por parada del plan.
            Route::get('/{planId}/eta', [PlanController::class, 'eta'])->name('eta')->whereUuid('planId');

            // Ajuste manual: agregar/mover un envío a este camión.
            Route::post('/{planId}/stops', [PlanController::class, 'assignShipment'])->name('stops.assign')->whereUuid('planId');

            // Reordenar la secuencia de paradas del plan.
            Route::put('/{planId}/sequence', [PlanController::class, 'reorderStops'])->name('sequence.reorder')->whereUuid('planId');

            // Publicar el plan: lo deja disponible para la ejecución.
            Route::post('/{planId}/publish', [PlanController::class, 'publish'])->name('publish')->whereUuid('planId');
        });
    },
];
