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
    |
    | B-5: el default es `null` A PROPÓSITO (fail-safe): sin env, el fan-out en vivo no se emite por
    | ningún lado en vez de fugar eventos a un `log` ruidoso. CONSECUENCIA en producción: el mapa de flota
    | en vivo NO emitirá por WebSocket hasta fijar BROADCAST_CONNECTION=pusher (apuntando al servicio
    | Soketi self-host). No se cambia el comportamiento aquí; solo se documenta la env a definir en prod.
    */

    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'null')),

    /*
    |--------------------------------------------------------------------------
    | Origen del WebSocket del CLIENTE (navegador → Soketi expuesto)
    |--------------------------------------------------------------------------
    | El navegador conecta a este host:puerto (p. ej. localhost:6001), distinto del host de red docker
    | que usa el app (`soketi`). Se usa para agregar el origen a la CSP connect-src del admin
    | (SecurityHeaders), sin relajar la política más de lo necesario. Coincide con VITE_PUSHER_*.
    */
    'client_ws' => [
        'host' => env('VITE_PUSHER_HOST', 'localhost'),
        'port' => (int) env('VITE_PUSHER_PORT', 6001),
        'scheme' => env('VITE_PUSHER_SCHEME', 'http'),
    ],

    'connections' => [

        // Protocolo Pusher contra Soketi (self-host, open-source). Host/puerto apuntan al
        // servicio `soketi`; en el cliente, VITE_PUSHER_* del cliente web.
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
