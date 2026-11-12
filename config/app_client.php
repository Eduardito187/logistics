<?php

declare(strict_types=1);

/*
| Configuración que el servidor le dicta a la app Flutter (GET /api/v1/app/app-config) y versión mínima
| soportada (426 Upgrade Required). La lee solo App\Http\Support\AppClientConfig, que tiene defaults
| funcionales en código: en un docker nuevo sin variables no se bloquea ninguna versión, el mapa usa las
| teselas de `maps.*` (OSM) y los intervalos son los de siempre.
*/
return [
    'version' => [
        // false = solo se informa X-Min-App-Version, sin rechazar con 426.
        'enforce' => env('APP_MIN_VERSION_ENFORCE', true),
        // Mínima por plataforma (MAJOR.MINOR.PATCH); null = sin mínima.
        'min' => [
            'default' => env('APP_MIN_VERSION'),
            'android' => env('APP_MIN_VERSION_ANDROID'),
            'ios' => env('APP_MIN_VERSION_IOS'),
            'web' => env('APP_MIN_VERSION_WEB'),
        ],
        'latest' => [
            'default' => env('APP_LATEST_VERSION'),
            'android' => env('APP_LATEST_VERSION_ANDROID'),
            'ios' => env('APP_LATEST_VERSION_IOS'),
        ],
        // URL de la tienda para actualizar (va en el 426 y en app-config).
        'store_url' => [
            'default' => env('APP_UPDATE_URL'),
            'android' => env('APP_UPDATE_URL_ANDROID'),
            'ios' => env('APP_UPDATE_URL_IOS'),
        ],
    ],

    // nombre => bool. Se editan desde Configuración cuando exista la sección «Mobile app».
    'feature_flags' => [],

    // Vacío = mismas teselas que el admin (maps.tile_url / maps.tile_attribution / maps.tile_max_zoom).
    'map' => [
        'tile_url' => env('APP_MAP_TILE_URL'),
        'attribution' => env('APP_MAP_ATTRIBUTION'),
        'max_zoom' => env('APP_MAP_MAX_ZOOM', env('MAP_TILE_MAX_ZOOM', 19)),
    ],

    'support' => [
        'email' => env('APP_SUPPORT_EMAIL'),
        'phone' => env('APP_SUPPORT_PHONE'),
        'whatsapp' => env('APP_SUPPORT_WHATSAPP'),
        'hours' => env('APP_SUPPORT_HOURS'),
    ],

    // Segundos (acotados a [5, 86400] por AppClientConfig).
    'polling' => [
        'notifications_seconds' => (int) env('APP_POLL_NOTIFICATIONS_SECONDS', 60),
        'tasks_seconds' => (int) env('APP_POLL_TASKS_SECONDS', 60),
        'location_seconds' => (int) env('APP_POLL_LOCATION_SECONDS', 30),
        'outbox_drain_seconds' => (int) env('APP_POLL_OUTBOX_DRAIN_SECONDS', 900),
        'app_config_seconds' => (int) env('APP_POLL_APP_CONFIG_SECONDS', 3600),
    ],

    'field' => [
        // Radio de la geocerca de llegada a la parada, en metros.
        'geofence_radius_m' => (int) env('APP_GEOFENCE_RADIUS_M', 150),
    ],
];
