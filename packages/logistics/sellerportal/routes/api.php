<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\SellerPortal\Presentation\Http\Controller\GetSellerStockController;
use Logistics\SellerPortal\Presentation\Http\Controller\ListSellerAsnsController;
use Logistics\SellerPortal\Presentation\Http\Controller\ListSellerSettlementsController;

/*
 * Fragmento de rutas del package Seller Portal (Fase 5, ADR 017). Puerta `integrations`, prefijo
 * `seller-portal`. Devuelve el mapa puerta => closure que espera routes/api.php (el dueño de ese archivo
 * raíz lo monta con `require __DIR__.'/api/seller-portal.php'`; esta sesión no toca la raíz).
 *
 * Autenticación por TOKEN DE INTEGRACIÓN scoped al seller:
 *  - `access.token`                        autentica el Bearer y deja permisos + token_id en la request.
 *  - `access.permission:sellerportal.read` exige que el token conceda el permiso de lectura del portal.
 *  El `sellerId` se resuelve del propio token (ver SellerContextResolver), así que NO viaja en la ruta:
 *  un seller solo puede leer lo suyo. (La puerta `integrations` ya aplica service.auth + idempotency.)
 *
 * Contrato en openapi/v1.yaml (snippet en el package: openapi-seller-portal.snippet.yaml).
 */
return [
    'integrations' => static function (): void {
        Route::prefix('seller-portal')
            ->name('seller-portal.')
            ->middleware(['access.token', 'access.permission:sellerportal.read'])
            ->group(function (): void {
                Route::get('asns', ListSellerAsnsController::class)->name('asns.index');
                Route::get('stock', GetSellerStockController::class)->name('stock.index');
                Route::get('settlements', ListSellerSettlementsController::class)->name('settlements.index');
            });
    },
];
