<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
        {{-- Panel de edición --}}
        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">{{ __('Coverage zone') }}</x-slot>

                <div class="space-y-4">
                    <div>
                        <x-dismac-select
                            property="zoneId"
                            live
                            :label="__('Edit existing zone')"
                            :placeholder="'— ' . __('New zone') . ' —'"
                            :options="array_merge(
                                [['value' => '', 'label' => '— ' . __('New zone') . ' —']],
                                array_map(fn ($z) => ['value' => $z['id'], 'label' => $z['name']], $zoneOptions),
                            )"
                        />
                        <div class="mt-2 flex gap-2">
                            <x-filament::button size="xs" color="gray" wire:click="loadZone">{{ __('Load') }}</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="newZone">{{ __('New zone') }}</x-filament::button>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium">{{ __('Zone name') }}</label>
                        <input type="text" wire:model="name" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-white/5" placeholder="{{ __('e.g. South zone') }}">
                    </div>

                    <div>
                        <x-dismac-select
                            property="parentAreaId"
                            live
                            :label="__('Municipality / City')"
                            placeholder="—"
                            :options="array_merge(
                                [['value' => '', 'label' => '—']],
                                array_map(fn ($id, $cityName) => ['value' => (string) $id, 'label' => $cityName], array_keys($cities), array_values($cities)),
                            )"
                        />
                    </div>

                    <p class="text-xs text-gray-500">
                        {{ __('Use the polygon tool on the map to draw the coverage area. Click to add points, double-click to finish, drag points to adjust.') }}
                    </p>

                    <x-filament::button class="w-full" wire:click="save" icon="heroicon-m-check">
                        {{ __('Save zone') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        </div>

        {{-- Mapa --}}
        <div class="lg:col-span-3">
            <x-filament::section class="p-0">
                <div id="coverage-map" style="height: 70vh; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
            </x-filament::section>
        </div>
    </div>

    @assets
        {{-- Leaflet + Geoman vendorizados localmente: cero dependencias externas en runtime (servido desde self). --}}
        {{-- Orden crítico: leaflet.css/js ANTES de geoman y ANTES de dismac-maps.js. --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <link rel="stylesheet" href="{{ asset('vendor/leaflet-geoman/leaflet-geoman.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        {{-- Helper de teselas con fallback automático al OSM público si el self-host no responde. --}}
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
        <script src="{{ asset('vendor/leaflet-geoman/leaflet-geoman.min.js') }}"></script>
    @endassets

    @script
    <script>
        const container = document.getElementById('coverage-map');
        let zones = @js($zones);

        const map = L.map(container).setView([-16.5, -68.15], 11); // La Paz / Bolivia
        // Teselas self-host (tileserver OSM del proyecto); si falla, el helper cae automáticamente al OSM público.
        window.DismacMaps.tileLayer(L, {
            url: @js(config('maps.tile_url')),
            fallbackUrl: @js(config('maps.tile_fallback_url')),
            attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
            maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
        }).addTo(map);

        const zonesLayer = L.featureGroup().addTo(map);
        let editLayer = null;

        function renderZones(list) {
            zonesLayer.clearLayers();
            (list || []).forEach((z) => {
                try {
                    const g = JSON.parse(z.geojson);
                    const layer = L.geoJSON(g, {
                        style: { color: z.active ? '#E2231A' : '#9a9a9a', weight: 2, fillColor: '#E2231A', fillOpacity: 0.12 },
                    }).bindPopup('<strong>' + z.name + '</strong>');
                    layer.addTo(zonesLayer);
                } catch (e) {}
            });
            if (zonesLayer.getLayers().length) {
                try { map.fitBounds(zonesLayer.getBounds().pad(0.2)); } catch (e) {}
            }
        }
        renderZones(zones);

        map.pm.setLang('es');
        map.pm.addControls({
            position: 'topleft',
            drawMarker: false, drawPolyline: false, drawCircle: false, drawCircleMarker: false,
            drawRectangle: false, drawText: false, cutPolygon: false, rotateMode: false, dragMode: false,
        });

        function pushDrawn(layer) {
            try { $wire.set('drawnGeoJson', JSON.stringify(layer.toGeoJSON()), true); } catch (e) {}
        }

        map.on('pm:create', (e) => {
            if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} }
            editLayer = e.layer;
            pushDrawn(editLayer);
            editLayer.on('pm:edit', () => pushDrawn(editLayer));
        });

        // Editar una zona existente cargada desde el servidor
        $wire.on('zone-loaded', (ev) => {
            const raw = Array.isArray(ev) ? ev[0]?.geojson : ev.geojson;
            if (!raw) return;
            if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} }
            try {
                const gj = JSON.parse(raw);
                editLayer = L.geoJSON(gj, { style: { color: '#E2231A', weight: 3 } }).addTo(map);
                editLayer.eachLayer((l) => {
                    if (l.pm) { l.pm.enable({ allowSelfIntersection: false }); l.on('pm:edit', () => pushDrawn(l)); }
                });
                map.fitBounds(editLayer.getBounds().pad(0.2), { animate: true, duration: 0.8 });
            } catch (e) {}
        });

        // Zoom animado al conjunto de zonas del municipio elegido (dropdown Municipio/Ciudad con `live`).
        $wire.on('focus-area', (ev) => {
            const geojsons = Array.isArray(ev) ? ev[0]?.geojsons : ev.geojsons;
            if (!Array.isArray(geojsons) || !geojsons.length) return;
            try {
                const group = L.featureGroup();
                geojsons.forEach((raw) => {
                    try { L.geoJSON(JSON.parse(raw)).addTo(group); } catch (x) {}
                });
                if (group.getLayers().length) {
                    map.fitBounds(group.getBounds().pad(0.2), { animate: true, duration: 0.8 });
                }
            } catch (e) {}
        });

        // Municipio vacío (o sin zonas con geometría): re-encuadra todas las zonas del mapa.
        $wire.on('focus-reset', () => {
            try {
                if (zonesLayer.getLayers().length) {
                    map.fitBounds(zonesLayer.getBounds().pad(0.2), { animate: true, duration: 0.8 });
                }
            } catch (e) {}
        });

        $wire.on('zone-reset', () => {
            if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
        });

        $wire.on('zones-updated', (ev) => {
            const list = Array.isArray(ev) ? ev[0]?.zones : ev.zones;
            if (list) { zones = list; renderZones(zones); }
            if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
        });

        // El mapa necesita recalcular tamaño cuando el contenedor ya está visible
        setTimeout(() => map.invalidateSize(), 250);
    </script>
    @endscript
</x-filament-panels::page>
