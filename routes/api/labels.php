<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Labels\Presentation\Http\Controller\LabelController;
use Logistics\Labels\Presentation\Http\Controller\LabelPreEmissionController;
use Logistics\Labels\Presentation\Http\Controller\PrinterController;
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

        // Líneas de despacho del pedido (pantalla de etiquetado por producto): producto + origen/destino
        // humanos + envío (agrupación multishipping) + autorización + estado de etiqueta, por línea.
        Route::get('orders/{orderNumber}/dispatch-lines', [LabelController::class, 'dispatchLines'])
            ->name('orders.dispatch-lines');

        // FALLBACK ONLINE: resuelve una etiqueta escaneada (ULID del QR) al bulto físico + envío. El flujo
        // normal es offline-first (label_id→unit_id ya viene en EmittedLabel y UnitTracking cacheados).
        Route::get('labels/{labelId}/resolve', [LabelController::class, 'resolveUnit'])
            ->name('labels.resolve');

        // Impresoras de red registradas centralmente ("Impresoras de tu sede"): la app no puede descubrirlas
        // cuando WiFi y cable están en redes separadas. Alcance por nodo del usuario; supervisor/admin, todas.
        Route::get('printers', PrinterController::class)->name('printers.index');
    },

    // Puerta integrations — procesos internos (worker de planificación: pre-emisión al publicar un plan).
    'integrations' => static function (): void {
        Route::prefix('orders/{orderNumber}/labels')->name('labels.orders.labels.')->group(function (): void {
            // Pre-emisión idempotente de las etiquetas del pedido (planning.plan_published).
            Route::post('pre-emit', LabelPreEmissionController::class)->name('pre-emit');
        });
    },
];
