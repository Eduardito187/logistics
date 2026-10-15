<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Notifications\Presentation\Http\Controller\AdvanceCascadeController;
use Logistics\Notifications\Presentation\Http\Controller\GetNotificationController;
use Logistics\Notifications\Presentation\Http\Controller\GetSmsBudgetController;
use Logistics\Notifications\Presentation\Http\Controller\ListMessageTemplatesController;
use Logistics\Notifications\Presentation\Http\Controller\ListMessageTypeRulesController;
use Logistics\Notifications\Presentation\Http\Controller\ListNotificationsController;
use Logistics\Notifications\Presentation\Http\Controller\ListShipmentNotificationsController;
use Logistics\Notifications\Presentation\Http\Controller\QueueNotificationController;
use Logistics\Notifications\Presentation\Http\Controller\SetMessageTypeRuleController;
use Logistics\Notifications\Presentation\Http\Controller\SuppressNotificationController;
use Logistics\Notifications\Presentation\Http\Controller\UpsertMessageTemplateController;

/*
| Fragmento de rutas del contexto notifications.
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware + rate limit los pone routes/api.php). Rutas relativas a la puerta.
|
| El contrato de estos endpoints vive en openapi/paths/notifications.yaml.
*/

return [
    // Puerta app — operación/soporte: log, reglas de cascada, plantillas y presupuesto de SMS.
    'app' => static function (): void {
        Route::name('notifications.')->group(function (): void {
            // Log de notificaciones de un envío (shipment_id por query param) y log global.
            Route::get('notifications', ListShipmentNotificationsController::class)->name('index');
            Route::get('notifications/all', ListNotificationsController::class)->name('all');
            Route::get('notifications/{notification}', GetNotificationController::class)->name('show');

            // Suprime una notificación encolada (soporte).
            Route::post('notifications/{notification}/suppress', SuppressNotificationController::class)->name('suppress');

            // Reglas de cascada configurables por tipo de mensaje (P1-NOT-01).
            Route::get('notification-rules', ListMessageTypeRulesController::class)->name('rules.index');
            Route::put('notification-rules', SetMessageTypeRuleController::class)->name('rules.set');

            // Plantillas de mensaje (P1-NOT-08).
            Route::get('notification-templates', ListMessageTemplatesController::class)->name('templates.index');
            Route::put('notification-templates', UpsertMessageTemplateController::class)->name('templates.upsert');

            // Presupuesto mensual de SMS (P1-NOT-05).
            Route::get('notification-sms-budget', GetSmsBudgetController::class)->name('sms-budget');
        });
    },

    // Puerta channels — app de clientes / web / call center: historial de notificaciones del envío.
    'channels' => static function (): void {
        Route::get('shipments/{shipment}/notifications', ListShipmentNotificationsController::class)
            ->name('shipments.notifications.index');
    },

    // Puerta integrations — SAP / transportistas / webhooks (credenciales de servicio).
    'integrations' => static function (): void {
        // Encola una notificación (envío real por outbox): responde 202.
        Route::post('notifications', QueueNotificationController::class)
            ->name('notifications.queue');

        // Avanza la cascada de una notificación (callback del relay / apertura): responde 202.
        Route::post('notifications/{notification}/advance-cascade', AdvanceCascadeController::class)
            ->name('notifications.advance-cascade');
    },
];
