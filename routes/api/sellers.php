<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Sellers\Presentation\Http\Controller\GetSellerController;
use Logistics\Sellers\Presentation\Http\Controller\ListSellersController;
use Logistics\Sellers\Presentation\Http\Controller\RegisterSellerController;
use Logistics\Sellers\Presentation\Http\Controller\SetSettlementTermsController;
use Logistics\Sellers\Presentation\Http\Controller\UpsertSellerSkuController;

/*
 * Rutas del contexto Sellers (marketplace). Todo en la puerta `integrations` (credenciales de servicio):
 * el alta y la gestión de sellers la hace un sistema integrado, no la app de campo ni los canales.
 * Contrato en openapi/v1.yaml (ADR 017, Fase 1).
 */
return [
    'integrations' => static function (): void {
        Route::prefix('sellers')->name('sellers.')->group(function (): void {
            Route::post('/', RegisterSellerController::class)->name('register');
            Route::get('/', ListSellersController::class)->name('index');
            Route::get('{sellerId}', GetSellerController::class)->name('show');
            Route::put('{sellerId}/settlement-terms', SetSettlementTermsController::class)->name('settlement-terms.set');
            Route::post('{sellerId}/skus', UpsertSellerSkuController::class)->name('skus.upsert');
        });
    },
];
