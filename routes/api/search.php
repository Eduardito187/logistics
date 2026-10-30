<?php

declare(strict_types=1);

use Commerce\Search\Presentation\Http\Controller\ListFacetsController;
use Commerce\Search\Presentation\Http\Controller\SearchProductsController;
use Illuminate\Support\Facades\Route;

/*
 * Rutas del contexto Search (búsqueda y navegación del catálogo, read-side). Viven en la puerta
 * `channels` (storefront / clientes): las consume la app y el storefront, no las integraciones de
 * servicio. Todo es lectura (CQRS query side). Contrato en openapi/v1.yaml.
 *
 * Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
 * (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta.
 */
return [
    'channels' => static function (): void {
        Route::prefix('search')->name('search.')->group(function (): void {
            Route::get('products', SearchProductsController::class)->name('products');
            Route::get('facets', ListFacetsController::class)->name('facets');
        });
    },
];
