<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Returns\Presentation\Http\Controller\DispositionReturnController;
use Logistics\Returns\Presentation\Http\Controller\GetReturnController;
use Logistics\Returns\Presentation\Http\Controller\InitiateReturnController;
use Logistics\Returns\Presentation\Http\Controller\ListReturnsController;
use Logistics\Returns\Presentation\Http\Controller\ReceiveReturnController;

/*
 * Rutas del contexto Returns (logística inversa / devoluciones). Puerta `integrations` (seller-facing /
 * sistemas integrados). Contrato en openapi/v1.yaml (ADR 017, Fase 4).
 */
return [
    'integrations' => static function (): void {
        Route::prefix('returns')->name('returns.')->group(function (): void {
            Route::post('', InitiateReturnController::class)->name('initiate');
            Route::get('', ListReturnsController::class)->name('index');
            Route::get('{returnId}', GetReturnController::class)->name('show');
            Route::post('{returnId}/receive', ReceiveReturnController::class)->name('receive');
            Route::post('{returnId}/disposition', DispositionReturnController::class)->name('disposition');
        });
    },
];
