<?php

declare(strict_types=1);

// Capa de teselas (tiles) de los mapas Leaflet del admin y del seguimiento público. Todo self-host
// sobre OpenStreetMap: sin Google, sin tiles de pago ni claves de API. El tileserver propio del
// docker-compose sirve el estilo osm-bright; en cada entorno se apunta MAP_TILE_URL a la instancia real.
return [
    // Plantilla de URL de las teselas (patrón Leaflet {z}/{x}/{y}). Por defecto, el tileserver self-host
    // del docker-compose. Dejarla VACÍA fuerza el fallback al tile público de OSM (solo demo: respeta la
    // política de uso de OSM y no sirve para producción). Las vistas resuelven ese fallback.
    'tile_url' => env('MAP_TILE_URL', 'http://localhost:8082/styles/osm-bright/{z}/{x}/{y}.png'),

    // Atribución (obligatoria por la licencia ODbL de OpenStreetMap).
    'tile_attribution' => env('MAP_TILE_ATTRIBUTION', '&copy; OpenStreetMap'),

    // Zoom máximo que ofrecen las teselas.
    'tile_max_zoom' => (int) env('MAP_TILE_MAX_ZOOM', 19),

    // URL pública de OSM usada como fallback cuando el tileserver self-host no está disponible. Se usa el
    // endpoint moderno SIN subdominio (tile.openstreetmap.org), más fiable que el patrón {s} ya en desuso.
    'tile_fallback_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
];
