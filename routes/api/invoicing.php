<?php

declare(strict_types=1);

use Commerce\Invoicing\Presentation\Http\Controller\CancelInvoiceController;
use Commerce\Invoicing\Presentation\Http\Controller\GetInvoiceByOrderController;
use Commerce\Invoicing\Presentation\Http\Controller\GetInvoiceController;
use Commerce\Invoicing\Presentation\Http\Controller\RenderInvoiceDocumentController;
use Illuminate\Support\Facades\Route;

/*
 * Fragmento de rutas del contexto commerce/invoicing (frente comercial, ADR 018 / EC-F0-10).
 *
 * Devuelve un mapa puerta => closure; el integrador (routes/api.php) invoca cada closure dentro del grupo
 * de su puerta (prefijo + middleware). Rutas relativas a la puerta. Los controllers invocan Application vía
 * QueryBus/CommandBus del contenedor. Contrato en openapi/v1.yaml.
 *
 * Puerta integrations (credenciales de servicio): consulta y gestión de la factura — la factura se emite
 * automáticamente cuando la orden se paga (OrderPaidInvoiceDispatcher), aquí se expone y se anula.
 */
return [
    'integrations' => static function (): void {
        Route::prefix('invoicing')->name('invoicing.')->group(function (): void {
            // GET /api/v1/integrations/invoicing/invoices/{id} — detalle de la factura (404 si no existe).
            Route::get('invoices/{id}', GetInvoiceController::class)->name('invoices.show');
            // GET /api/v1/integrations/invoicing/invoices/by-order/{orderId} — factura de una orden (404 si no existe).
            Route::get('invoices/by-order/{orderId}', GetInvoiceByOrderController::class)->name('invoices.by-order');
            // POST /api/v1/integrations/invoicing/invoices/{id}/cancel — anular factura (202, idempotente).
            Route::post('invoices/{id}/cancel', CancelInvoiceController::class)->name('invoices.cancel');
            // GET /api/v1/integrations/invoicing/invoices/{id}/document — documento fiscal interno (HTML imprimible).
            Route::get('invoices/{id}/document', RenderInvoiceDocumentController::class)->name('invoices.document');
        });
    },
];
