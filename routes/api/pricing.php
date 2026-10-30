<?php

declare(strict_types=1);

use Commerce\Pricing\Presentation\Http\Controller\ActivatePriceListController;
use Commerce\Pricing\Presentation\Http\Controller\ArchivePriceListController;
use Commerce\Pricing\Presentation\Http\Controller\CreatePriceListController;
use Commerce\Pricing\Presentation\Http\Controller\GetPriceController;
use Commerce\Pricing\Presentation\Http\Controller\ListPriceListsController;
use Commerce\Pricing\Presentation\Http\Controller\ListPricesController;
use Commerce\Pricing\Presentation\Http\Controller\SetPriceController;
use Illuminate\Support\Facades\Route;

/*
 * Fragmento de rutas del contexto commerce/pricing.
 *
 * Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
 * (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta. Los controllers invocan
 * Application vía QueryBus/CommandBus del contenedor. Contrato en openapi/v1.yaml.
 *
 * Dos audiencias:
 *   - channels      → storefront/clientes (B2C): lecturas del precio vigente (CQRS query side).
 *   - integrations  → gestión PIM (credenciales de servicio): alta/activación/archivo de listas y precios.
 */
return [
    // Puerta channels — storefront/clientes: resolver precios vigentes (solo lectura).
    'channels' => static function (): void {
        Route::prefix('pricing')->name('pricing.')->group(function (): void {
            // GET /api/v1/channels/pricing/price — precio vigente de un SKU (404 si no hay).
            Route::get('price', GetPriceController::class)->name('price');
            // GET /api/v1/channels/pricing/prices — ítems de una lista (?price_list_id=), paginable (E-02).
            Route::get('prices', ListPricesController::class)->name('prices.index');
        });
    },

    // Puerta integrations — gestión PIM: administración de listas y precios (escritura asíncrona 202).
    'integrations' => static function (): void {
        Route::prefix('pricing')->name('pricing.')->group(function (): void {
            // GET /api/v1/integrations/pricing/price-lists — listas de precios (?channel= opcional).
            Route::get('price-lists', ListPriceListsController::class)->name('price-lists.index');
            // POST /api/v1/integrations/pricing/price-lists — crear lista (202).
            Route::post('price-lists', CreatePriceListController::class)->name('price-lists.store');
            // POST /api/v1/integrations/pricing/price-lists/{id}/prices — fijar precio de un SKU (202).
            Route::post('price-lists/{id}/prices', SetPriceController::class)->name('price-lists.prices.set');
            // POST /api/v1/integrations/pricing/price-lists/{id}/activate — activar lista (202).
            Route::post('price-lists/{id}/activate', ActivatePriceListController::class)->name('price-lists.activate');
            // POST /api/v1/integrations/pricing/price-lists/{id}/archive — archivar lista (202).
            Route::post('price-lists/{id}/archive', ArchivePriceListController::class)->name('price-lists.archive');
        });
    },
];
