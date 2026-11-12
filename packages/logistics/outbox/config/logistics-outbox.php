<?php

declare(strict_types=1);
use Commerce\Cart\Infrastructure\Dispatch\OrderPlacedCartConverter;
use Commerce\Invoicing\Infrastructure\Dispatch\OrderPaidInvoiceDispatcher;
use Commerce\Orders\Infrastructure\Dispatch\OrderPaidShipmentDispatcher;
use Commerce\Orders\Infrastructure\Dispatch\PaymentCapturedOrderDispatcher;
use Commerce\Payments\Infrastructure\Dispatch\OrderCancelledRefundDispatcher;
use Commerce\Search\Infrastructure\Dispatch\CatalogProductIndexerDispatcher;
use Logistics\Fleet\Infrastructure\Outbox\VehicleIncidentFleetDispatcher;
use Logistics\Inventory\Infrastructure\Dispatch\GoodsReceivedStockDispatcher;
use Logistics\Inventory\Infrastructure\Dispatch\ReturnRestockDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\CommerceEventNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\DomainEventNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\FleetDocumentExpiryNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\VehicleIncidentNotificationDispatcher;
use Logistics\Outbox\Infrastructure\Dispatch\FanOutMessageDispatcher;
use Logistics\Outbox\Infrastructure\Dispatch\NullMessageDispatcher;
use Logistics\Planning\Infrastructure\Outbox\VehicleIncidentReplanningDispatcher;
use Logistics\Promise\Infrastructure\Dispatch\OrderCancelledCupoReleaseDispatcher;
use Logistics\Promise\Infrastructure\Dispatch\OrderPaidCupoConfirmDispatcher;
use Logistics\Shipments\Infrastructure\Dispatch\OrderCancelledFulfillmentDispatcher;
use Logistics\Shipments\Infrastructure\Dispatch\ShipmentStatusProgressionDispatcher;
use Logistics\Tracking\Infrastructure\Outbox\BindShipmentOnReceived;
use Logistics\Tracking\Infrastructure\Outbox\CancelOnOrderCancelled;
use Logistics\Tracking\Infrastructure\Outbox\InProgressOnLabelsEmitted;
use Logistics\Tracking\Infrastructure\Outbox\OpenTrackingOnOrderPlaced;
use Logistics\Tracking\Infrastructure\Outbox\ProcessingOnOrderPaid;
use Logistics\Tracking\Infrastructure\Outbox\ProjectExecutionTracking;
use Logistics\Tracking\Infrastructure\Outbox\RescheduledOnReschedule;
use Logistics\Tracking\Infrastructure\Outbox\ReturnedOnReturnInitiated;

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
            // Conector SAP (ADR 002): los mensajes con destino SAP van a su PROPIA cola `sap`, con workers
            // dedicados. Así un SAP lento o caído nunca frena a los demás reactores de la cola `outbox`.
            'sap_connector.' => 'sap',
        ],

        // Cola para los eventos sin prefijo que matchee (inbound, returns, fanout, ...).
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

        // Búsqueda (ADR 018, Fase 0): indexado incremental del catálogo en la proyección FTS. El prefijo
        // 'catalog.product_' cubre drafted/published/archived (gana por longest-prefix; no la categoría).
        'catalog.product_' => CatalogProductIndexerDispatcher::class,

        // Comercio (ADR 018/019, Fase 0): pagos → órdenes → fulfillment + avisos al cliente. Varios
        // eventos tienen MÁS de un reactor, así que van por abanico (ver 'fanout'):
        //   payments.captured  → marca la orden pagada (MarkOrderPaid) + avisa "pago confirmado".
        //   orders.order_paid  → crea el envío (ReceiveShipment) + emite la factura.
        //   orders.order_placed→ marca el carrito convertido + avisa "pedido recibido".
        'payments.captured' => FanOutMessageDispatcher::class,
        'payments.failed' => CommerceEventNotificationDispatcher::class,
        'orders.order_paid' => FanOutMessageDispatcher::class,
        'orders.order_placed' => FanOutMessageDispatcher::class,
        // Cancelación de orden (ADR 020): varios reactores (fulfillment + reembolso) → abanico.
        'orders.order_cancelled' => FanOutMessageDispatcher::class,

        // SEGUIMIENTO (ciclo de vida del pedido de cara al cliente): los hechos por envío avanzan el estado
        // visible. Van por ABANICO junto al aviso al cliente (el nombre exacto gana a 'execution.'/'shipments.'
        // por longest-prefix; la notificación se preserva incluyéndola en el fanout).
        'execution.shipment_loaded' => FanOutMessageDispatcher::class,
        'execution.shipment_departed' => FanOutMessageDispatcher::class,
        'execution.shipment_delivered' => FanOutMessageDispatcher::class,
        'execution.delivery_failed' => FanOutMessageDispatcher::class,
        'shipments.shipment_received' => FanOutMessageDispatcher::class,
        'shipments.shipment_rescheduled' => FanOutMessageDispatcher::class,

        // AVANCE DEL ESTADO DEL ENVÍO (shipments.status): los hechos de planificación/ejecución mueven el
        // envío RECEIVED → PLANNED → IN_EXECUTION → DELIVERED (ShipmentStatusProgressionDispatcher, monótono
        // e idempotente). Los de ejecución que antes caían por prefijo a 'execution.' (solo notificación)
        // pasan a abanico preservando la notificación PRIMERO; el plan publicado no tenía ruta (caía al
        // dispatcher por defecto), así que va directo al reactor.
        'execution.shipment_prepared' => FanOutMessageDispatcher::class,
        'execution.route_stop_attached' => FanOutMessageDispatcher::class,
        'execution.driver_route_assembled' => FanOutMessageDispatcher::class,
        'planning.plan_published' => ShipmentStatusProgressionDispatcher::class,
        // Un solo reactor cada uno (no necesitan abanico): etiqueta generada → «en curso»; devolución → «devuelta».
        'labels.labels_emitted' => InProgressOnLabelsEmitted::class,
        'returns.return_initiated' => ReturnedOnReturnInitiated::class,
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

        // Comercio (ADR 018, Fase 0): una orden pagada dispara DOS reactores aguas abajo, cada uno
        // idempotente (el outbox reentrega at-least-once):
        'orders.order_paid' => [
            // Fulfillment: crea el envío en logística (ReceiveShipment, idempotente por ('commerce', orderId))
            // y pasa la orden a "fulfilling". Es el puente comercio → última milla.
            OrderPaidShipmentDispatcher::class,
            // Cupo (promise): confirma el cupo que la orden había reservado antes de pagar, para que el
            // barrido de vencimiento no lo libere ahora que la orden está pagada (ADR 020). Va después
            // del fulfillment para que el envío ya exista al conciliar la franja.
            OrderPaidCupoConfirmDispatcher::class,
            // Facturación: emite el documento fiscal de la orden (IssueInvoiceForOrder).
            OrderPaidInvoiceDispatcher::class,
            // Seguimiento: el pedido pasa a «procesando» (pago confirmado).
            ProcessingOnOrderPaid::class,
        ],

        // Checkout: al crear la orden desde un carrito, reactores idempotentes:
        'orders.order_placed' => [
            // Carrito: lo marca como convertido (MarkCartConverted) por el cart_id del evento.
            OrderPlacedCartConverter::class,
            // Notificaciones: avisa al cliente "pedido recibido, esperando el pago".
            CommerceEventNotificationDispatcher::class,
            // Seguimiento: abre el timeline del pedido en «pendiente».
            OpenTrackingOnOrderPlaced::class,
        ],

        // Pago capturado: puente a la orden + aviso al cliente, ambos idempotentes:
        'payments.captured' => [
            // Marca la orden como pagada (emite orders.order_paid, que a su vez abre su propio abanico).
            PaymentCapturedOrderDispatcher::class,
            // Notificaciones: aviso "pago confirmado".
            CommerceEventNotificationDispatcher::class,
        ],

        // Cancelación de orden (ADR 020): reacciona según la etapa del envío y reembolsa si estaba pagada.
        'orders.order_cancelled' => [
            // Fulfillment (shipments): cancela el envío o dispara la devolución según su estado.
            OrderCancelledFulfillmentDispatcher::class,
            // Pagos: reembolsa si la orden estaba pagada (idempotente; no-op si no estaba capturada).
            OrderCancelledRefundDispatcher::class,
            // Cupo (promise): libera el cupo reservado/confirmado y lo devuelve a capacity, en cualquier
            // etapa (pre o post pago) y venga de POS / manual / cron de expiración (ADR 020).
            OrderCancelledCupoReleaseDispatcher::class,
            // Seguimiento: el pedido pasa a «cancelada» (tolerante: no aplica si ya estaba entregado).
            CancelOnOrderCancelled::class,
        ],

        // Envío creado: aviso al cliente + liga el envío al seguimiento de su pedido (reconcilia multishipping).
        'shipments.shipment_received' => [
            DomainEventNotificationDispatcher::class,
            BindShipmentOnReceived::class,
        ],

        // Reprogramación de entrega: aviso al cliente + seguimiento → «reprogramada» (por envío).
        'shipments.shipment_rescheduled' => [
            DomainEventNotificationDispatcher::class,
            RescheduledOnReschedule::class,
        ],

        // Hechos de ejecución por envío: aviso al cliente + avance del seguimiento (cargada/en camino/
        // entregado/fallida). ProjectExecutionTracking mapea el tipo a estado visible y aplica tolerante.
        'execution.shipment_loaded' => [
            DomainEventNotificationDispatcher::class,
            ProjectExecutionTracking::class,
            // Estado del envío (shipments.status): SIEMPRE último y nunca lanza (no corta a los demás).
            ShipmentStatusProgressionDispatcher::class,
        ],
        'execution.shipment_departed' => [
            DomainEventNotificationDispatcher::class,
            ProjectExecutionTracking::class,
            // Estado del envío (shipments.status): SIEMPRE último y nunca lanza (no corta a los demás).
            ShipmentStatusProgressionDispatcher::class,
        ],
        'execution.shipment_delivered' => [
            DomainEventNotificationDispatcher::class,
            ProjectExecutionTracking::class,
            // Estado del envío (shipments.status): SIEMPRE último y nunca lanza (no corta a los demás).
            ShipmentStatusProgressionDispatcher::class,
        ],
        'execution.delivery_failed' => [
            DomainEventNotificationDispatcher::class,
            ProjectExecutionTracking::class,
            // Estado del envío (shipments.status): SIEMPRE último y nunca lanza (no corta a los demás).
            ShipmentStatusProgressionDispatcher::class,
        ],

        // Preparación / asignación a camión / ruta armada: aviso (si aplica) + avance del estado del envío.
        // La notificación va primero (preserva el ruteo previo por prefijo 'execution.'); el reactor de
        // envíos, último.
        'execution.shipment_prepared' => [
            DomainEventNotificationDispatcher::class,
            ShipmentStatusProgressionDispatcher::class,
        ],
        'execution.route_stop_attached' => [
            DomainEventNotificationDispatcher::class,
            ShipmentStatusProgressionDispatcher::class,
        ],
        'execution.driver_route_assembled' => [
            DomainEventNotificationDispatcher::class,
            ShipmentStatusProgressionDispatcher::class,
        ],
    ],

    // Observadores del procesamiento (punto de extensión genérico, ver OutboxObserver). Lista de
    // class-string ligados en el contenedor que ProcessOutboxMessage invoca DESPUÉS de persistir el
    // desenlace de CADA mensaje procesado, aislados en try/catch: un observador nunca cambia el despacho,
    // los reintentos ni el dead-letter. A diferencia de un reactor del abanico, no corta a nadie si falla.
    //
    // Cada contexto puede sumarse desde su propio provider (como las rutas). Hoy:
    //   - Conector SAP: SapNotificationRecorder registra el aviso a SAP de los eventos de despacho
    //     configurados y lo envía async por la cola `sap` (fire-and-forget; lo agrega su provider).
    'observers' => [],

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
