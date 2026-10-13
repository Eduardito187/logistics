<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Outbox\Presentation\Http\Controller\DiscardMessageController;
use Logistics\Outbox\Presentation\Http\Controller\GetOutboxMessageController;
use Logistics\Outbox\Presentation\Http\Controller\GetOutboxStatsController;
use Logistics\Outbox\Presentation\Http\Controller\ListOutboxMessagesController;
use Logistics\Outbox\Presentation\Http\Controller\RelayOutboxController;
use Logistics\Outbox\Presentation\Http\Controller\RetryMessageController;

/*
| Fragmento de rutas del bounded context Outbox (transactional outbox).
|
| Devuelve un mapa puerta => closure. El integrador (routes/api.php) invoca cada closure DENTRO del
| grupo de su puerta, que ya aplica el prefijo (/api/v1/<puerta>), el middleware de credenciales y el
| rate limit. Las rutas de aquí son relativas a la puerta.
|
| Las tres puertas (ver docs/architecture.md):
|   app           → app interna / torre de control (token usuario + dispositivo, roles)
|   channels      → app de clientes, web, call center (credenciales por canal) — el outbox NO expone nada aquí
|   integrations  → SAP, transportistas, servicios internos (credenciales de servicio)
*/

return [
    // Puerta app: tablero de operación del outbox (observar, reintentar y descartar mensajes).
    'app' => static function (): void {
        Route::prefix('outbox')->name('outbox.')->group(function (): void {
            Route::get('messages', ListOutboxMessagesController::class)->name('messages.index');
            Route::get('messages/stats', GetOutboxStatsController::class)->name('messages.stats');
            Route::get('messages/{id}', GetOutboxMessageController::class)->name('messages.show');
            Route::post('messages/{id}/retry', RetryMessageController::class)->name('messages.retry');
            Route::post('messages/{id}/discard', DiscardMessageController::class)->name('messages.discard');
        });
    },

    // Puerta integrations: disparo del relay bajo demanda y métricas para monitoreo de servicios.
    'integrations' => static function (): void {
        Route::prefix('outbox')->name('outbox.')->group(function (): void {
            Route::get('stats', GetOutboxStatsController::class)->name('stats');
            Route::post('relay', RelayOutboxController::class)->name('relay');
        });
    },
];
