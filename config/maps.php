<?php

declare(strict_types=1);

// Capa de teselas (tiles) de los mapas Leaflet del admin y del seguimiento público. Todo sobre
// OpenStreetMap: sin Google, sin tiles de pago ni claves de API.
//
// DEFAULT QUE FUNCIONA EN CUALQUIER ENTORNO (decisión del dueño, 2026-11-11: "que todo funcione al
// levantar el docker"): si no se configura nada, se usan las teselas públicas de OpenStreetMap. Antes el
// default era el tileserver self-host `http://localhost:8082`, que (a) solo existe si se levanta el perfil
// `geo` con varios GB de mbtiles de Bolivia y (b) desde un navegador remoto `localhost` es la PC de quien
// mira: en staging/cloud y en cualquier instalación nueva el mapa quedaba SIN FONDO. Solo andaba en la PC
// del dueño porque su `.env` tenía la URL pública a mano.
//
// Self-host (opt-in): MAP_TILE_URL=https://<host-público-del-tileserver>/styles/osm-bright/{z}/{x}/{y}.png
// (ver docs/maps-geocoder-selfhost.md). La CSP abre sola el origen configurado (SecurityHeaders).
return [
    // Plantilla de URL de las teselas (patrón Leaflet {z}/{x}/{y}).
    'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),

    // Atribución (obligatoria por la licencia ODbL de OpenStreetMap).
    'tile_attribution' => env('MAP_TILE_ATTRIBUTION', '&copy; OpenStreetMap'),

    // Zoom máximo que ofrecen las teselas.
    'tile_max_zoom' => (int) env('MAP_TILE_MAX_ZOOM', 19),

    // Sin segunda fuente: la URL de arriba ya es la que funciona por defecto. Se conserva la clave en null
    // por compatibilidad con vistas que aún la leen.
    'tile_fallback_url' => null,
];
