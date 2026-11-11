/**
 * Helper compartido de teselas (tiles) para todos los mapas Leaflet del proyecto.
 *
 * Fuente de teselas: config/maps.php → MAP_TILE_URL. Por defecto las públicas de OpenStreetMap
 * (funcionan en cualquier entorno sin levantar nada); el tileserver self-host es opcional (perfil `geo`)
 * apuntando MAP_TILE_URL a su URL PÚBLICA. Una sola fuente, sin fallback.
 *
 * Uso en un blade (tras cargar Leaflet y este script):
 *   window.DismacMaps.tileLayer(L, {
 *     url:        @js(config('maps.tile_url')),
 *     attribution:@js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
 *     maxZoom:    @js((int) config('maps.tile_max_zoom', 19)),
 *   }).addTo(map);
 *
 * La atribución de texto "&copy; OpenStreetMap" se MANTIENE: es la atribución ODbL de los datos de
 * OpenStreetMap, no un CDN de teselas.
 */
(function () {
    'use strict';

    function createTileLayer(L, options) {
        options = options || {};

        var attribution = options.attribution || '&copy; OpenStreetMap';
        var maxZoom = typeof options.maxZoom === 'number' ? options.maxZoom : 19;

        // Sin URL primaria (self-host) configurada: no se agrega capa de teselas. No hay fallback
        // público por política de no-CDN; se registra un aviso y el mapa queda sin fondo.
        if (!options.url) {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn(
                    'DismacMaps: falta MAP_TILE_URL (tileserver self-host); el mapa no mostrará teselas.'
                );
            }
            return null;
        }

        // Única fuente de teselas: el tileserver self-host (options.url). Sin fallback público.
        return L.tileLayer(options.url, { attribution: attribution, maxZoom: maxZoom });
    }

    window.DismacMaps = { tileLayer: createTileLayer };
})();
