<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Labels\Presentation\Http\Controller\LabelController;
use Logistics\Labels\Presentation\Http\Controller\LabelPreEmissionController;
use Logistics\Labels\Presentation\Http\Controller\PublicKeyController;

/*
| Fragmento de rutas del contexto Etiquetas (labels).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta.
| Todos los endpoints pertenecen a la puerta app (token usuario + dispositivo). Rutas relativas a la
| puerta.
*/

return [
    // Puerta app — app interna Flutter.
    'app' => static function (): void {
        // Bundle de claves públicas para verificación offline (solo claves públicas).
        Route::prefix('labels')->name('labels.')->group(function (): void {
            Route::get('public-keys', PublicKeyController::class)->name('public-keys');
        });

        // Etiquetas de un pedido: emitir, reimprimir, obtener (offline-friendly) y ZPL para Zebra.
        Route::prefix('orders/{orderNumber}/labels')->name('orders.labels.')->group(function (): void {
            Route::get('/', [LabelController::class, 'index'])->name('index');
            Route::post('/', [LabelController::class, 'store'])->name('store');
            Route::post('reprint', [LabelController::class, 'reprint'])->name('reprint');
            Route::get('zpl', [LabelController::class, 'zpl'])->name('zpl');
        });
    },

    // Puerta integrations — procesos internos (worker de planificación: pre-emisión al publicar un plan).
    'integrations' => static function (): void {
        Route::prefix('orders/{orderNumber}/labels')->name('labels.orders.labels.')->group(function (): void {
            // Pre-emisión idempotente de las etiquetas del pedido (planning.plan_published).
            Route::post('pre-emit', LabelPreEmissionController::class)->name('pre-emit');
        });
    },
];
