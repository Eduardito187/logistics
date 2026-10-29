<?php

declare(strict_types=1);

use Commerce\Payments\Presentation\Http\Controller\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Rutas del contexto Payments (frente comercial, ADR 018 / EC-F0-07). El webhook de confirmación de la
 * pasarela vive en la puerta `integrations` (credenciales de servicio): lo invoca el proveedor de pago,
 * no la app ni los canales. La captura es idempotente. Contrato en openapi/v1.yaml.
 */
return [
    'integrations' => static function (): void {
        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::post('webhook', PaymentWebhookController::class)->name('webhook');
        });
    },
];
