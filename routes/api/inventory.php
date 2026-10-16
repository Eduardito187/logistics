<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Inventory\Presentation\Http\Controller\GetAvailableToPromiseController;
use Logistics\Inventory\Presentation\Http\Controller\GetStockOnHandController;
use Logistics\Inventory\Presentation\Http\Controller\ListLotsController;

/*
 * Rutas del contexto Inventory (stock con atribución de dueño). Solo LECTURA, en la puerta `integrations`
 * (lo consume el motor de promesa y el portal del seller). Contrato en openapi/v1.yaml (ADR 017, Fase 3).
 */
return [
    'integrations' => static function (): void {
        Route::prefix('inventory')->name('inventory.')->group(function (): void {
            Route::get('on-hand', GetStockOnHandController::class)->name('on-hand');
            Route::get('available-to-promise', GetAvailableToPromiseController::class)->name('atp');
            Route::get('lots', ListLotsController::class)->name('lots');
        });
    },
];
