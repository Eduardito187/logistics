/**
 * Helper compartido de teselas (tiles) para todos los mapas Leaflet del proyecto.
 *
 * Problema que resuelve: el tileserver OSM self-host (config/maps.php → MAP_TILE_URL, por defecto
 * http://localhost:8082/...) puede no estar levantado en un entorno (demo, laptop, CI). En ese caso
 * Leaflet pedía teselas que fallaban y el mapa quedaba gris. Este helper crea la capa con la URL
 * primaria (self-host) y, si varias teselas fallan, cae UNA sola vez al OSM público de forma
 * automática — así el mapa siempre muestra fondo, con self-host o sin él, sin tocar la vista.
 *
 * Uso en un blade (tras cargar Leaflet y este script):
 *   window.DismacMaps.tileLayer(L, {
 *     url:        @js(config('maps.tile_url')),
 *     fallbackUrl:@js(config('maps.tile_fallback_url')),
 *     attribution:@js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
 *     maxZoom:    @js((int) config('maps.tile_max_zoom', 19)),
 *   }).addTo(map);
 */
(function () {
    'use strict';

    // OSM público moderno (sin subdominio). La CSP del admin lo permite explícitamente.
    var DEFAULT_FALLBACK = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    function createTileLayer(L, options) {
        options = options || {};

        var fallbackUrl = options.fallbackUrl || DEFAULT_FALLBACK;
        var attribution = options.attribution || '&copy; OpenStreetMap';
        var maxZoom = typeof options.maxZoom === 'number' ? options.maxZoom : 19;

        // Sin URL primaria configurada → directo al fallback público (modo demo).
        var usingFallback = !options.url;
        var url = usingFallback ? fallbackUrl : options.url;

        var layer = L.tileLayer(url, { attribution: attribution, maxZoom: maxZoom });

        // Si la capa primaria (self-host) falla en varias teselas, baja al OSM público una sola vez.
        if (!usingFallback) {
            var errorCount = 0;
            layer.on('tileerror', function () {
                errorCount += 1;
                if (!usingFallback && errorCount >= 3) {
                    usingFallback = true;
                    layer.setUrl(fallbackUrl);
                }
            });
        }

        return layer;
    }

    window.DismacMaps = { tileLayer: createTileLayer };
})();
