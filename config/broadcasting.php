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
    | Por defecto es el MISMO origen de la página: nginx desvía a Soketi las conexiones WebSocket de
    | `/app/<clave>`, así que el navegador conecta a `ws(s)://<host de la web>[:puerto]` sin configurar
    | nada. Con host y puerto en null (default) el admin (resources/js/echo.js) usa el origen de la página
    | y la CSP (SecurityHeaders) permite el ws(s) del request actual.
    |
    | OVERRIDE opcional: solo si Soketi se publica aparte (p. ej. desarrollo con el 6001 directo) se fijan
    | VITE_PUSHER_HOST / VITE_PUSHER_PORT / VITE_PUSHER_SCHEME. Esas variables las lee el build de Vite
    | (echo.js) y, acá, la CSP agrega ese origen a connect-src. Host null + puerto fijado = mismo host de
    | la página en ese puerto. Scheme null = el del request.
    */
    'client_ws' => [
        'host' => env('VITE_PUSHER_HOST'),
        'port' => env('VITE_PUSHER_PORT') === null ? null : (int) env('VITE_PUSHER_PORT'),
        'scheme' => env('VITE_PUSHER_SCHEME'),
    ],

    'connections' => [

        // Protocolo Pusher contra Soketi (self-host, open-source). Host/puerto apuntan al
        // servicio `soketi` (backend → Soketi); el navegador conecta por el mismo origen de la web.
        //
        // Los defaults son LOS MISMOS que trae el servicio `soketi` de docker-compose.yml
        // (SOKETI_DEFAULT_APP_ID/KEY/SECRET) y el host es el nombre del servicio en la red docker: en un
        // docker nuevo basta BROADCAST_CONNECTION=pusher, sin más variables. En staging/producción se
        // DEBEN fijar PUSHER_APP_KEY y PUSHER_APP_SECRET propios (el secreto firma el acceso a los canales
        // privados, p. ej. `private-user.{id}` de la app).
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'dismaclocalkey'),
            'secret' => env('PUSHER_APP_SECRET', 'dismaclocalsecret'),
            'app_id' => env('PUSHER_APP_ID', 'dismac'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'host' => env('PUSHER_HOST', 'soketi'),
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
