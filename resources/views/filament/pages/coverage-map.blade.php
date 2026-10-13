<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
        {{-- Panel de edición --}}
        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">{{ __('Coverage zone') }}</x-slot>

                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium">{{ __('Edit existing zone') }}</label>
                        <select wire:model="zoneId" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-white/5">
                            <option value="">— {{ __('New zone') }} —</option>
                            @foreach ($zones as $z)
                                <option value="{{ $z['id'] }}">{{ $z['name'] }}</option>
                            @endforeach
                        </select>
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
                        <label class="text-sm font-medium">{{ __('Municipality / City') }}</label>
                        <select wire:model="parentAreaId" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-white/5">
                            <option value="">—</option>
                            @foreach ($cities as $id => $cityName)
                                <option value="{{ $id }}">{{ $cityName }}</option>
                            @endforeach
                        </select>
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
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <link rel="stylesheet" href="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script src="https://unpkg.com/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.min.js"></script>
    @endassets

    @script
    <script>
        const container = document.getElementById('coverage-map');
        let zones = @js($zones);

        const map = L.map(container).setView([-16.5, -68.15], 11); // La Paz / Bolivia
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19,
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
                map.fitBounds(editLayer.getBounds().pad(0.2));
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
