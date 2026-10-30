<?php

declare(strict_types=1);

use Commerce\Orders\Presentation\Http\Controller\CheckoutController;
use Commerce\Orders\Presentation\Http\Controller\GetOrderController;
use Commerce\Orders\Presentation\Http\Controller\PlaceOrderController;
use Illuminate\Support\Facades\Route;

/*
| Fragmento de rutas del contexto commerce/orders (checkout del storefront).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta. Los controllers invocan
| Application vía CommandBus/QueryBus del contenedor. Contrato en openapi/v1.yaml.
*/

return [
    // Puerta channels — storefront/clientes: crear órdenes (checkout) y consultarlas.
    'channels' => static function (): void {
        // POST /api/v1/channels/checkout — checkout orquestado: toma un carrito existente y despacha
        // PlaceOrderFromCart (202, escritura asíncrona). La conversión del carrito va por outbox.
        Route::post('checkout', CheckoutController::class)->name('checkout');

        Route::prefix('orders')->name('orders.')->group(function (): void {
            // POST /api/v1/channels/orders — checkout: despacha PlaceOrder (202, escritura asíncrona).
            Route::post('/', PlaceOrderController::class)->name('place');
            // GET /api/v1/channels/orders/{order} — consulta una orden por id (404 si no existe).
            Route::get('{order}', GetOrderController::class)->name('show');
        });
    },
];
