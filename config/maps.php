<?php

declare(strict_types=1);

// Capa de teselas (tiles) de los mapas Leaflet del admin y del seguimiento público. Todo self-host
// sobre OpenStreetMap: sin Google, sin tiles de pago ni claves de API. El tileserver propio del
// docker-compose sirve el estilo osm-bright; en cada entorno se apunta MAP_TILE_URL a la instancia real.
return [
    // Plantilla de URL de las teselas (patrón Leaflet {z}/{x}/{y}). Única fuente: el tileserver self-host
    // del docker-compose. Si queda vacía o el tileserver no está levantado, el mapa no muestra teselas
    // (no hay fallback público por política de no-CDN; ver 'tile_fallback_url' más abajo).
    'tile_url' => env('MAP_TILE_URL', 'http://localhost:8082/styles/osm-bright/{z}/{x}/{y}.png'),

    // Atribución (obligatoria por la licencia ODbL de OpenStreetMap).
    'tile_attribution' => env('MAP_TILE_ATTRIBUTION', '&copy; OpenStreetMap'),

    // Zoom máximo que ofrecen las teselas.
    'tile_max_zoom' => (int) env('MAP_TILE_MAX_ZOOM', 19),

    // Fallback público ELIMINADO por política de no-CDN: los mapas usan SOLO el tileserver self-host
    // (MAP_TILE_URL). Se conserva la clave en null por compatibilidad con vistas que aún la leen; el
    // helper dismac-maps.js ya no la usa y NUNCA se pega a un CDN público de teselas.
    'tile_fallback_url' => null,
];
