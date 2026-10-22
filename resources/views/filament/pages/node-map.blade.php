<x-filament-panels::page>
    {{-- Mapa de nodos de la red --}}
    <x-filament::section class="p-0">
        <div id="node-map" style="height: 75vh; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
    </x-filament::section>

    @assets
        {{-- Leaflet vendorizado localmente: cero dependencias externas en runtime (servido desde self). --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        {{-- Helper de teselas con fallback automático al OSM público si el self-host no responde. --}}
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
    @endassets

    @script
    <script>
        const container = document.getElementById('node-map');
        const nodes = @js($nodes);

        const map = L.map(container).setView([-16.5, -68.15], 6); // Bolivia
        // Teselas self-host (tileserver OSM del proyecto); si falla, el helper cae automáticamente al OSM público.
        window.DismacMaps.tileLayer(L, {
            url: @js(config('maps.tile_url')),
            fallbackUrl: @js(config('maps.tile_fallback_url')),
            attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
            maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
        }).addTo(map);

        // Color del marcador según el estado del nodo (verde activo, gris si no).
        function colorFor(status) {
            return String(status || '').toLowerCase() === 'active' ? '#16a34a' : '#9a9a9a';
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));
        }

        const group = L.featureGroup().addTo(map);

        (nodes || []).forEach((n) => {
            const lat = Number(n.lat);
            const lng = Number(n.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }

            const marker = L.circleMarker([lat, lng], {
                radius: 8,
                color: colorFor(n.status),
                fillColor: colorFor(n.status),
                fillOpacity: 0.85,
                weight: 2,
            });

            const popup =
                '<strong>' + escapeHtml(n.code) + '</strong><br>' +
                escapeHtml(n.name) + '<br>' +
                '<span class="text-xs text-gray-500">' +
                escapeHtml(n.type) + ' · ' + escapeHtml(n.status) +
                '</span>';
            marker.bindPopup(popup);
            marker.addTo(group);
        });

        // Auto-zoom a todos los nodos; si no hay ninguno, el mapa queda centrado en Bolivia.
        if (group.getLayers().length) {
            try { map.fitBounds(group.getBounds().pad(0.2)); } catch (e) {}
        }

        // El mapa necesita recalcular tamaño cuando el contenedor ya está visible.
        setTimeout(() => map.invalidateSize(), 250);
    </script>
    @endscript
</x-filament-panels::page>
