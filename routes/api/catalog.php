<?php

declare(strict_types=1);

use Commerce\Catalog\Presentation\Http\Controller\GetProductAvailabilityController;
use Commerce\Catalog\Presentation\Http\Controller\GetProductController;
use Commerce\Catalog\Presentation\Http\Controller\ListCategoriesController;
use Commerce\Catalog\Presentation\Http\Controller\ListProductsController;
use Illuminate\Support\Facades\Route;

/*
 * Rutas del contexto Catalog (frente comercial). Las lecturas del catálogo viven en la puerta
 * `channels` (storefront / clientes): las consume la app y el storefront, no las integraciones de
 * servicio. Todo es lectura (CQRS query side). Contrato en openapi/v1.yaml.
 *
 * Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
 * (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta.
 */
return [
    'channels' => static function (): void {
        Route::prefix('catalog')->name('catalog.')->group(function (): void {
            Route::get('products', ListProductsController::class)->name('products.index');
            Route::get('products/{id}', GetProductController::class)->name('products.show');
            Route::get('products/{id}/availability', GetProductAvailabilityController::class)->name('products.availability');
            Route::get('categories', ListCategoriesController::class)->name('categories.index');
        });
    },
];
