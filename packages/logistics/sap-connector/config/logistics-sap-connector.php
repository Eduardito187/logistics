<?php

declare(strict_types=1);

// Configuración del conector SAP (capa anti-corrupción, ADR 002). Los secretos (credenciales de SAP)
// van en .env / gestor de secretos, nunca en el repo ni en settings editables (P1-INFRA-04).
return [
    'sap' => [
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
    ],

    // Mapeo centro/almacén por defecto (P2-SAP-06). Si una recepción no resuelve centro/almacén por el
    // mapa de materiales, se puede usar este como respaldo configurable por entorno.
    'defaults' => [
        'center' => env('SAP_CONNECTOR_DEFAULT_CENTER'),
        'warehouse' => env('SAP_CONNECTOR_DEFAULT_WAREHOUSE'),
    ],
];
