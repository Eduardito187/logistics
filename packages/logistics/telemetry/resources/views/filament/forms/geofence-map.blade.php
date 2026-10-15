{{-- Campo de dibujo de geocercas: mapa Leaflet sobre OpenStreetMap con Leaflet-Geoman para dibujar y --}}
{{-- editar UN polígono. El estado del campo (lista de {lat,lng}) se entabla con Alpine/Livewire: al --}}
{{-- dibujar o editar el polígono, se vuelca el estado; al cargar un valor existente, se pinta. --}}
<x-dynamic-component
    :component="$field->getFieldWrapperView()"
    :field="$field"
>
    @php
        $mapId = 'geofence-map-'.\Illuminate\Support\Str::random(8);
    @endphp

    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle('{{ $field->getStatePath() }}'),
            map: null,
            drawnLayer: null,

            init() {
                const el = this.$refs.map;
                this.map = L.map(el).setView([-16.5, -68.15], 11); // Bolivia
                // Teselas self-host con fallback automático al OSM público (helper compartido): si el
                // tileserver del proyecto no responde, la capa baja sola al tile público y el mapa no queda gris.
                window.DismacMaps.tileLayer(L, {
                    url: @js(config('maps.tile_url')),
                    fallbackUrl: @js(config('maps.tile_fallback_url')),
                    attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
                    maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
                }).addTo(this.map);

                // Controles de Geoman: solo polígono (dibujar, editar, arrastrar, borrar).
                this.map.pm.addControls({
                    position: 'topleft',
                    drawMarker: false,
                    drawCircle: false,
                    drawCircleMarker: false,
                    drawPolyline: false,
                    drawRectangle: false,
                    drawText: false,
                    drawPolygon: true,
                    editMode: true,
                    dragMode: true,
                    cutPolygon: false,
                    rotateMode: false,
                    removalMode: true,
                });

                this.drawExisting();

                // Al crear un polígono se descartan los anteriores: una geocerca = un polígono.
                this.map.on('pm:create', (e) => {
                    if (this.drawnLayer) {
                        this.map.removeLayer(this.drawnLayer);
                    }
                    this.drawnLayer = e.layer;
                    this.sync();
                    this.drawnLayer.on('pm:edit', () => this.sync());
                    this.drawnLayer.on('pm:dragend', () => this.sync());
                });

                this.map.on('pm:remove', () => {
                    this.drawnLayer = null;
                    this.state = [];
                });

                setTimeout(() => this.map.invalidateSize(), 300);
            },

            // Pinta el polígono que ya está en el estado (edición de una geocerca existente).
            drawExisting() {
                const pts = (this.state || []).filter((p) => p && p.lat != null && p.lng != null);
                if (pts.length < 3) {
                    return;
                }
                const latlngs = pts.map((p) => [p.lat, p.lng]);
                this.drawnLayer = L.polygon(latlngs, { color: '#E2231A', weight: 3 }).addTo(this.map);
                this.drawnLayer.on('pm:edit', () => this.sync());
                this.drawnLayer.on('pm:dragend', () => this.sync());
                try { this.map.fitBounds(this.drawnLayer.getBounds().pad(0.2)); } catch (e) {}
            },

            // Vuelca los vértices del polígono dibujado al estado del campo como lista de {lat,lng}.
            sync() {
                if (!this.drawnLayer) {
                    this.state = [];
                    return;
                }
                const geo = this.drawnLayer.toGeoJSON();
                const ring = geo && geo.geometry && Array.isArray(geo.geometry.coordinates)
                    ? geo.geometry.coordinates[0] : null;
                if (!Array.isArray(ring)) {
                    this.state = [];
                    return;
                }
                // GeoJSON entrega [lng, lat] y cierra el anillo repitiendo el primer punto: lo quitamos.
                let coords = ring.map((c) => ({ lat: c[1], lng: c[0] }));
                if (coords.length > 1) {
                    const first = coords[0];
                    const last = coords[coords.length - 1];
                    if (first.lat === last.lat && first.lng === last.lng) {
                        coords = coords.slice(0, -1);
                    }
                }
                this.state = coords;
            },
        }"
    >
        <div x-ref="map" style="height: 55vh; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Draw the geofence polygon on the map. Use the edit tools to adjust or remove it.') }}
        </p>
    </div>

    @assets
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
        <link rel="stylesheet" href="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.css" />
        <script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.min.js"></script>
    @endassets
</x-dynamic-component>
