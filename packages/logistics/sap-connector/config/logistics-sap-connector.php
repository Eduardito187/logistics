<?php

declare(strict_types=1);

use Logistics\SapConnector\Application\Notification\SapNotifiableEvents;

// Configuración del conector SAP (capa anti-corrupción, ADR 002). Los secretos (credenciales de SAP)
// van en .env / gestor de secretos, nunca en el repo ni en settings editables (P1-INFRA-04).
return [
    // Cola dedicada a TODO lo que habla con SAP (avisos, reconciliación, importación y los mensajes del
    // outbox con prefijo `sap_connector.`). Tiene sus propios workers: un SAP lento no frena a nadie más.
    'queue' => env('SAP_CONNECTOR_QUEUE', 'sap'),

    'sap' => [
        // Interruptor REAL del conector. Apagado ⇒ stub Null (no sale nada hacia SAP) y los avisos se
        // registran Skipped. El panel de Configuración lo overlaya desde `integrations.sap.enabled`.
        'enabled' => (bool) env('SAP_CONNECTOR_ENABLED', true),

        // Endpoint base del servicio SAP (OData/REST). Sin base_url el conector cae al stub Null, para
        // que la plataforma arranque y se pueda desarrollar sin SAP: el escaneo nunca depende de SAP.
        'base_url' => env('SAP_CONNECTOR_BASE_URL'),

        // Credenciales de servicio para la autenticación básica contra SAP.
        'username' => env('SAP_CONNECTOR_USERNAME'),
        'password' => env('SAP_CONNECTOR_PASSWORD'),

        // Rutas relativas de los recursos SAP, configurables por si cambian entre entornos.
        'orders_path' => env('SAP_CONNECTOR_ORDERS_PATH', '/orders/pending'),
        'stock_transfers_path' => env('SAP_CONNECTOR_STOCK_TRANSFERS_PATH', '/stock-transfers'),

        // Timeout por request en segundos. Hablar con SAP nunca debe colgar un worker de despacho.
        'timeout' => (int) env('SAP_CONNECTOR_TIMEOUT', 10),

        // Ruta del endpoint que recibe los avisos fire-and-forget de hechos de despacho (POST).
        'events_path' => env('SAP_CONNECTOR_EVENTS_PATH', '/events'),
    ],

    // Avisos fire-and-forget a SAP (principio del dueño: a SAP le importan stock y registros; a nuestro
    // despacho, no). Tras cada hecho de la lista se registra el aviso en `sap_notifications`, se envía
    // async por la cola `sap` y se guarda la respuesta cruda. Errores, lentitud o caída de SAP NUNCA
    // afectan nada de nuestro lado. Todo esto es editable desde Configuración → Integrations → SAP.
    'notifications' => [
        // Eventos que se avisan (event_name exacto del outbox). Por defecto, todo el catálogo.
        'events' => SapNotifiableEvents::defaults(),

        // Catálogo de eventos avisables (event_name => etiqueta), opciones del panel de Configuración.
        'catalog' => SapNotifiableEvents::CATALOG,

        // Intentos totales por aviso. Agotados, queda Failed y se deja de insistir (sin dead-letter).
        'max_attempts' => (int) env('SAP_NOTIFICATIONS_MAX_ATTEMPTS', 3),

        // Timeout HTTP corto del aviso, en segundos (el connect nunca supera este valor ni 3s).
        'timeout' => (int) env('SAP_NOTIFICATIONS_TIMEOUT', 5),

        // Backoff: minutos de espera antes del próximo intento, por intento ya realizado.
        'backoff_minutes' => [1 => 1, 2 => 5],

        // Gracia (segundos) de un aviso recién registrado antes de que el barrido pueda re-encolarlo
        // (su job ya va en la cola): evita encolarlo dos veces.
        'grace_seconds' => (int) env('SAP_NOTIFICATIONS_GRACE_SECONDS', 120),

        // Lote del barrido de reintentos por corrida (lotes chicos) y lease al re-encolar (segundos).
        'retry_batch' => (int) env('SAP_NOTIFICATIONS_RETRY_BATCH', 50),
        'requeue_lease_seconds' => (int) env('SAP_NOTIFICATIONS_REQUEUE_LEASE', 300),
    ],

    // Reconciliación de traspasos de stock. Los traspasos NUEVOS los empuja solo el reactor del outbox;
    // el scheduler reintenta únicamente los Failed con el backoff vencido (sin doble envío).
    'transfers' => [
        // Minutos desde el último intento antes de reintentar un traspaso Failed (scheduler).
        'retry_backoff_minutes' => (int) env('SAP_TRANSFERS_RETRY_BACKOFF_MINUTES', 5),

        // Un Pending sin tocar hace más de N minutos se considera estancado (solo lo rescata la corrida
        // manual o `--include-stale-pending`), para no pisar al reactor del outbox.
        'stale_pending_minutes' => (int) env('SAP_TRANSFERS_STALE_PENDING_MINUTES', 10),

        // Tope de traspasos por corrida (el HTTP a SAP es lento: lotes chicos).
        'batch' => (int) env('SAP_TRANSFERS_BATCH', 50),
    ],

    // Mapeo centro/almacén por defecto (P2-SAP-06). Si una recepción no resuelve centro/almacén por el
    // mapa de materiales, se puede usar este como respaldo configurable por entorno.
    'defaults' => [
        'center' => env('SAP_CONNECTOR_DEFAULT_CENTER'),
        'warehouse' => env('SAP_CONNECTOR_DEFAULT_WAREHOUSE'),
    ],

    // Dimensión de propiedad / consignación (ADR 017, roadmap marketplace §6.4). Regla DURA: el stock de
    // un seller (consignación) NUNCA se postea a SAP como material propio de Dismac. La política define
    // qué se hace con la titularidad:
    //  - 'omit' (implementado, default): la consignación es un movimiento puramente logístico y no se
    //    postea titularidad a SAP (el traspaso queda Sent sin tocar SAP). Es la opción segura.
    //  - 'consignment_warehouse' (futuro): postear a un almacén/tipo de stock de consignación en SAP; aún
    //    no implementado porque requiere un puerto SAP dedicado (NO reutilizar el posteo de stock propio).
    'consignment' => [
        'posting_policy' => env('SAP_CONNECTOR_CONSIGNMENT_POLICY', 'omit'),
    ],
];
