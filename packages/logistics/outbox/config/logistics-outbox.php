<?php

declare(strict_types=1);
use Commerce\Invoicing\Infrastructure\Dispatch\OrderPaidInvoiceDispatcher;
use Commerce\Orders\Infrastructure\Dispatch\OrderPaidShipmentDispatcher;
use Commerce\Orders\Infrastructure\Dispatch\PaymentCapturedOrderDispatcher;
use Logistics\Fleet\Infrastructure\Outbox\VehicleIncidentFleetDispatcher;
use Logistics\Inventory\Infrastructure\Dispatch\GoodsReceivedStockDispatcher;
use Logistics\Inventory\Infrastructure\Dispatch\ReturnRestockDispatcher;
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
        // protege de reintentos solapados si el worker muere a mitad del despacho. Con el dispatcher
        // (pump) el mensaje viaja por la cola antes de despacharse, así que el lease debe cubrir la
        // latencia de cola + el despacho; 120s da margen sin arriesgar duplicados (el despacho es
        // idempotente de todos modos).
        'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 120),

        // Intentos totales antes de descartar el mensaje a dead-letter.
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 6),

        // Backoff exponencial: minutos de espera antes del próximo intento, por intento ya realizado.
        // 1→1min, 2→5min, 3→30min, 4→2h, 5→6h; el 6º fallo descarta.
        'backoff_minutes' => [1 => 1, 2 => 5, 3 => 30, 4 => 120, 5 => 360],
    ],

    // Dispatcher (pump): el comando `logistics:outbox:pump` toma un lote y encola UN job por mensaje a las
    // colas always-on para que los workers despachen en paralelo (C-2 de la auditoría de throughput). Como
    // el pump no hace I/O externo (solo toma y encola), el lote por corrida es grande: el techo ya no es
    // el del despacho secuencial inline.
    'pump' => [
        // Máximo de mensajes que el pump toma y encola por corrida.
        'batch' => (int) env('OUTBOX_PUMP_BATCH', 1000),

        // Ruteo por cola según el prefijo del event_name (match por prefijo más largo). Aísla el
        // head-of-line blocking: los avisos al cliente van a la cola `notifications` y el resto a `outbox`,
        // cada una con sus propios workers. Un destino lento en una cola no frena a la otra.
        'queue_routes' => [
            'shipments.' => 'notifications',
            'execution.' => 'notifications',
            'tracking.' => 'notifications',
            'notifications.' => 'notifications',
            'fleet.documents_expiry_alerted' => 'notifications',
        ],

        // Cola para los eventos sin prefijo que matchee (SAP, inbound, returns, fanout, ...).
        'default_queue' => (string) env('OUTBOX_PUMP_DEFAULT_QUEUE', 'outbox'),
    ],

    // Circuit breaker por destino externo (A-5 de la auditoría). Estado compartido en Valkey (cache):
    // si un destino (SAP/FCM/Telegram/email/SMS) acumula N fallos seguidos dentro de la ventana, el
    // circuito se ABRE y los intentos cortan rápido (fast-fail) durante el enfriamiento, para no gastar
    // el timeout completo ni frenar a los destinos sanos. Pasado el enfriamiento entra en half-open.
    'breaker' => [
        // Fallos seguidos que abren el circuito de un destino.
        'failure_threshold' => (int) env('OUTBOX_BREAKER_FAILURES', 5),

        // Segundos que el circuito permanece abierto (fast-fail) antes de reintentar (half-open).
        'cooldown_seconds' => (int) env('OUTBOX_BREAKER_COOLDOWN', 30),

        // Ventana (segundos) en la que se cuentan los fallos seguidos.
        'window_seconds' => (int) env('OUTBOX_BREAKER_WINDOW', 60),
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

        // Marketplace (ADR 017): la recepción de proveedores se vuelve stock con dueño. El evento
        // `inbound.goods_received` crea un lote de inventario por cada línea aceptada (propio o consignación).
        'inbound.goods_received' => GoodsReceivedStockDispatcher::class,

        // Logística inversa (Fase 4): una devolución dispuesta como "restock" reingresa su producto al
        // lote del dueño correcto. El dispatcher ignora disposiciones return_to_seller/scrap.
        'returns.return_dispositioned' => ReturnRestockDispatcher::class,

        // Comercio (ADR 018, Fase 0): puente de pagos → órdenes → fulfillment.
        //   payments.captured  → marca la orden como pagada (emite orders.order_paid).
        //   orders.order_paid  → crea el envío (ReceiveShipment, idempotente por orden) y pasa a fulfilling.
        'payments.captured' => PaymentCapturedOrderDispatcher::class,
        // orders.order_paid tiene DOS reactores (crear envío + emitir factura) → abanico (ver 'fanout').
        'orders.order_paid' => FanOutMessageDispatcher::class,
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

    // Poda de retención del outbox (comando logistics:outbox:prune). SOLO estados terminales:
    // dispatched (ventana corta: ya cumplieron su función) y dead_lettered (ventana larga: diagnóstico).
    // NUNCA toca pending/failed.
    'prune' => [
        // Días de retención de mensajes DESPACHADOS (se miden desde dispatched_at).
        'dispatched_days' => (int) env('OUTBOX_PRUNE_DISPATCHED_DAYS', 14),

        // Días de retención de mensajes en DEAD-LETTER (se miden desde created_at). Más largo para
        // poder diagnosticar fallos de entrega antes de descartarlos.
        'dead_lettered_days' => (int) env('OUTBOX_PRUNE_DEAD_LETTERED_DAYS', 90),

        // Filas por lote de borrado (acota el bloqueo).
        'chunk' => (int) env('DB_PRUNE_CHUNK', 5000),

        // Pausa entre lotes en milisegundos (alivia la carga del motor en producción).
        'pause_ms' => (int) env('DB_PRUNE_PAUSE_MS', 100),
    ],
];
