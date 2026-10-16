<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Settlement\Presentation\Http\Controller\ConfirmSettlementController;
use Logistics\Settlement\Presentation\Http\Controller\DraftSellerSettlementController;
use Logistics\Settlement\Presentation\Http\Controller\GetSettlementController;
use Logistics\Settlement\Presentation\Http\Controller\ListSettlementsController;
use Logistics\Settlement\Presentation\Http\Controller\MarkSettlementPaidController;

/*
 * Rutas del contexto Settlement (liquidación de sellers). Todo en la puerta `integrations`
 * (credenciales de servicio). Contrato en openapi/v1.yaml (ADR 017, Fase 4).
 */
return [
    'integrations' => static function (): void {
        Route::prefix('settlements')->name('settlements.')->group(function (): void {
            Route::post('draft', DraftSellerSettlementController::class)->name('draft');
            Route::get('/', ListSettlementsController::class)->name('index');
            Route::get('{settlementId}', GetSettlementController::class)->name('show');
            Route::post('{settlementId}/confirm', ConfirmSettlementController::class)->name('confirm');
            Route::post('{settlementId}/pay', MarkSettlementPaidController::class)->name('pay');
        });
    },
];
