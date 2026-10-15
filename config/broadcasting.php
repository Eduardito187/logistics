<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Conexión de broadcasting por defecto
    |--------------------------------------------------------------------------
    | El fan-out en vivo (telemetría: posición de vehículos) viaja por WebSockets.
    | Usamos el protocolo Pusher contra un servidor OPEN-SOURCE self-host: Soketi
    | (servicio `soketi` en docker-compose). NO usamos Pusher.com ni Google: solo
    | el protocolo. Driver por defecto configurable por env (pusher | redis | log | null).
    */

    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'null')),

    'connections' => [

        // Protocolo Pusher contra Soketi (self-host, open-source). Host/puerto apuntan al
        // servicio `soketi`; en el cliente, VITE_PUSHER_* (ver resources/js/echo.js).
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'host' => env('PUSHER_HOST', '127.0.0.1'),
                'port' => (int) env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => env('PUSHER_SCHEME', 'http') === 'https',
                'useTLS' => env('PUSHER_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle options para el envío server→Soketi (timeouts cortos, best-effort).
                'timeout' => 4,
                'connect_timeout' => 2,
            ],
        ],

        // Pub/sub de Valkey (built-in, sin servidor WS). Útil como transporte entre nodos
        // o si se cambia el frente a un bridge Redis→WS. No requiere paquete adicional.
        'redis' => [
            'driver' => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
