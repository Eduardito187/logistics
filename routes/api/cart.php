<?php

declare(strict_types=1);

use Commerce\Cart\Presentation\Http\Controller\AddItemController;
use Commerce\Cart\Presentation\Http\Controller\OpenCartController;
use Commerce\Cart\Presentation\Http\Controller\RemoveItemController;
use Commerce\Cart\Presentation\Http\Controller\ShowCartController;
use Commerce\Cart\Presentation\Http\Controller\UpdateItemQtyController;
use Illuminate\Support\Facades\Route;

/*
 * Fragmento de rutas del contexto commerce/cart (frente comercial, ADR 018).
 *
 * Devuelve un mapa puerta => closure; el integrador invoca la closure dentro del grupo de su puerta
 * (prefijo + middleware los pone routes/api.php). El carrito vive en la puerta `channels`
 * (storefront/clientes): abrir carrito, verlo y manipular sus líneas. Contrato en openapi/v1.yaml.
 */
return [
    'channels' => static function (): void {
        Route::prefix('cart')->name('cart.')->group(function (): void {
            Route::post('/', OpenCartController::class)->name('open');
            Route::get('{cart}', ShowCartController::class)->name('show');
            Route::post('{cart}/items', AddItemController::class)->name('items.add');
            Route::patch('{cart}/items/{sku}', UpdateItemQtyController::class)->name('items.update');
            Route::delete('{cart}/items/{sku}', RemoveItemController::class)->name('items.remove');
        });
    },
];
