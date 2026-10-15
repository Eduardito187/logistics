<?php

declare(strict_types=1);
use Logistics\Fleet\Infrastructure\Outbox\VehicleIncidentFleetDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\DomainEventNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\FleetDocumentExpiryNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\VehicleIncidentNotificationDispatcher;
use Logistics\Outbox\Infrastructure\Dispatch\FanOutMessageDispatcher;
use Logistics\Outbox\Infrastructure\Dispatch\NullMessageDispatcher;
use Logistics\Planning\Infrastructure\Outbox\VehicleIncidentReplanningDispatcher;

// Configuración del Transactional Outbox (ADR 002). Valores afinables por entorno vía .env.
return [
    // Parámetros del relay (worker que despacha los mensajes).
    'relay' => [
        // Máximo de mensajes que un lote toma por corrida.
        'limit' => (int) env('OUTBOX_RELAY_LIMIT', 100),

        // Segundos que un mensaje tomado queda "en vuelo" (lease) antes de volver a ser elegible:
        // protege de reintentos solapados si el worker muere a mitad del despacho.
        'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 60),

        // Intentos totales antes de descartar el mensaje a dead-letter.
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 6),

        // Backoff exponencial: minutos de espera antes del próximo intento, por intento ya realizado.
        // 1→1min, 2→5min, 3→30min, 4→2h, 5→6h; el 6º fallo descarta.
        'backoff_minutes' => [1 => 1, 2 => 5, 3 => 30, 4 => 120, 5 => 360],
    ],

    // Ruteo por nombre de evento hacia el adapter que lo despacha. Clave = prefijo del event_name
    // (match por prefijo más largo); valor = class-string de un MessageDispatcher ligado en el
    // contenedor (lo aporta cada contexto/conector: SAP, FCM, email, SMS, webhooks). Mientras un
    // canal no tenga adapter real, su prefijo se omite y cae al dispatcher por defecto (ver abajo).
    //
    // Ejemplo (cuando el conector SAP exponga su adapter):
    //   'sap.' => \Logistics\SapConnector\Infrastructure\Outbox\SapMessageDispatcher::class,
    'routes' => [
        // Notificaciones al cliente (P1-NOT): los hechos de Envíos, Ejecución y Tracking se enrutan al
        // DomainEventNotificationDispatcher, que traduce cada evento a su tipo de mensaje y encola
        // QueueNotification de forma idempotente (ver README del package notifications). El resto de
        // prefijos (p. ej. 'sap.') sigue cayendo al dispatcher por defecto hasta tener su adapter.
        'shipments.' => DomainEventNotificationDispatcher::class,
        'execution.' => DomainEventNotificationDispatcher::class,
        'tracking.' => DomainEventNotificationDispatcher::class,

        // Incidente de camión: un mismo hecho dispara reacciones en VARIOS contextos (flota,
        // planificación y notificaciones), así que su nombre EXACTO se rutea al abanico (gana por ser el
        // prefijo más largo frente a 'execution.'). Ver 'fanout' más abajo.
        'execution.vehicle_incident_reported' => FanOutMessageDispatcher::class,

        // Alerta de documentos de flota por vencer / vencidos (P1-FLT-04): el barrido programado emite
        // este resumen y notificaciones avisa al supervisor con un correo consolidado.
        'fleet.documents_expiry_alerted' => FleetDocumentExpiryNotificationDispatcher::class,
    ],

    // Abanico por evento: eventos que disparan reacciones en varios contextos a la vez. El
    // FanOutMessageDispatcher entrega el mensaje, en orden, a cada adapter configurado acá. Cada adapter
    // es idempotente (el outbox reentrega at-least-once).
    'fanout' => [
        'execution.vehicle_incident_reported' => [
            // Flota: si es MAYOR, el vehículo pasa a fuera de servicio (mantenimiento).
            VehicleIncidentFleetDispatcher::class,
            // Planificación: si es MAYOR, los envíos pendientes se encolan para replanificar el día siguiente.
            VehicleIncidentReplanningDispatcher::class,
            // Notificaciones: avisa a los clientes afectados (reprogramado / retraso) y al supervisor.
            VehicleIncidentNotificationDispatcher::class,
        ],
    ],

    // Dispatcher por defecto cuando ningún prefijo de 'routes' matchea: hoy NullMessageDispatcher
    // (solo log), reemplazable por un canal catch-all.
    'default_dispatcher' => NullMessageDispatcher::class,
];
