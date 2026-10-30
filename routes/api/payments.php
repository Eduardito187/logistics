<?php

declare(strict_types=1);

use Commerce\Payments\Presentation\Http\Controller\InitiatePaymentController;
use Commerce\Payments\Presentation\Http\Controller\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Rutas del contexto Payments (frente comercial, ADR 018 / EC-F0-07).
 *   channels      → el storefront INICIA el cobro de una orden (POST /channels/payments).
 *   integrations  → el webhook de confirmación de la pasarela (lo invoca el proveedor, no la app/canales).
 * La captura es idempotente. Contrato en openapi/v1.yaml.
 */
return [
    'channels' => static function (): void {
        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::post('/', InitiatePaymentController::class)->name('initiate');
        });
    },
    'integrations' => static function (): void {
        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::post('webhook', PaymentWebhookController::class)->name('webhook');
        });
    },
];
