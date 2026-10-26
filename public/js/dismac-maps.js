/**
 * Helper compartido de teselas (tiles) para todos los mapas Leaflet del proyecto.
 *
 * Política de la plataforma: CERO CDN público. Los mapas usan ÚNICAMENTE el tileserver OSM self-host
 * (config/maps.php → MAP_TILE_URL, por defecto http://localhost:8082/...). Se eliminó el antiguo
 * fallback al OSM público: si el tileserver self-host no está levantado, el mapa simplemente no
 * muestra teselas (comportamiento aceptado) y NUNCA se pega a un CDN público de teselas.
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
