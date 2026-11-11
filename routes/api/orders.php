<?php

declare(strict_types=1);

use Commerce\Orders\Presentation\Http\Controller\CancelOrderController;
use Commerce\Orders\Presentation\Http\Controller\CheckoutController;
use Commerce\Orders\Presentation\Http\Controller\GetOrderController;
use Commerce\Orders\Presentation\Http\Controller\PlaceOrderController;
use Commerce\Orders\Presentation\Http\Controller\RecordInitialPaymentController;
use Commerce\Orders\Presentation\Http\Controller\SignOrderContractController;
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
            // POST /api/v1/channels/orders/{order}/cancel — cancela la orden (source='pos'); 202 acuse,
            // 404 si no existe. Body opcional { reason?: string }. Escritura asíncrona (outbox).
            Route::post('{order}/cancel', CancelOrderController::class)->name('cancel');
        });
    },

    // Puerta integrations — backend de POS / servicios: cancelar órdenes (source='pos').
    'integrations' => static function (): void {
        Route::prefix('orders')->name('orders.')->group(function (): void {
            // POST /api/v1/integrations/orders/{order}/cancel — cancela la orden (source='pos'); 202 acuse,
            // 404 si no existe. Body opcional { reason?: string }. Escritura asíncrona (outbox).
            Route::post('{order}/cancel', CancelOrderController::class)->name('cancel');

            // Señales de CRÉDITO/minicuotas (POS/sistema de crédito). Body opcional { reference?: string }.
            // 202 acuse, 404 si no existe, 422 si la regla no aplica. Al cumplirse las condiciones del plan
            // la orden pasa a procesando (emite OrderPaid → crea envío → tracking «procesando»).
            Route::post('{order}/contract-signed', SignOrderContractController::class)->name('contract-signed');
            Route::post('{order}/initial-paid', RecordInitialPaymentController::class)->name('initial-paid');
        });
    },
];
