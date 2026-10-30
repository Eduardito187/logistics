<?php

declare(strict_types=1);

use Commerce\Promotions\Presentation\Http\Controller\ActivatePromotionController;
use Commerce\Promotions\Presentation\Http\Controller\ChangePromotionRulesController;
use Commerce\Promotions\Presentation\Http\Controller\CreatePromotionController;
use Commerce\Promotions\Presentation\Http\Controller\EvaluatePromotionsController;
use Commerce\Promotions\Presentation\Http\Controller\ExpirePromotionController;
use Commerce\Promotions\Presentation\Http\Controller\GetPromotionController;
use Commerce\Promotions\Presentation\Http\Controller\ListPromotionsController;
use Illuminate\Support\Facades\Route;

/*
 * Fragmento de rutas del contexto commerce/promotions (frente comercial).
 *
 * Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
 * (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta. Los controllers invocan
 * Application vía QueryBus/CommandBus del contenedor. Contrato en openapi/v1.yaml.
 *
 * Dos audiencias:
 *   - channels      → storefront/clientes (B2C): evaluación de promociones client-side (CQRS query side).
 *   - integrations  → gestión (credenciales de servicio): alta, activación, expiración y cambio de reglas.
 */
return [
    // Puerta channels — storefront/clientes: evaluar promociones aplicables a un carrito (solo lectura).
    'channels' => static function (): void {
        Route::prefix('promotions')->name('promotions.')->group(function (): void {
            // POST /api/v1/channels/promotions/evaluate — promos aplicables + descuentos (200, sin efecto).
            Route::post('evaluate', EvaluatePromotionsController::class)->name('evaluate');
        });
    },

    // Puerta integrations — gestión: administración de promociones (escritura asíncrona 202).
    'integrations' => static function (): void {
        Route::prefix('promotions')->name('promotions.')->group(function (): void {
            // GET /api/v1/integrations/promotions — lista de promociones (?status= opcional), paginable (E-02).
            Route::get('/', ListPromotionsController::class)->name('index');
            // GET /api/v1/integrations/promotions/{id} — consulta una promoción por id (404 si no existe).
            Route::get('{id}', GetPromotionController::class)->name('show');
            // POST /api/v1/integrations/promotions — crear promoción en borrador (202).
            Route::post('/', CreatePromotionController::class)->name('store');
            // POST /api/v1/integrations/promotions/{id}/activate — activar promoción (202).
            Route::post('{id}/activate', ActivatePromotionController::class)->name('activate');
            // POST /api/v1/integrations/promotions/{id}/expire — expirar promoción (202).
            Route::post('{id}/expire', ExpirePromotionController::class)->name('expire');
            // POST /api/v1/integrations/promotions/{id}/rules — cambiar reglas de la promoción (202).
            Route::post('{id}/rules', ChangePromotionRulesController::class)->name('rules.change');
        });
    },
];
