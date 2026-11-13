<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
        {{-- Panel de edición --}}
        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">{{ __('Coverage zone') }}</x-slot>

                <div class="space-y-4">
                    <div>
                        <x-dismac-select
                            wire:key="coverage-select-zone"
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
                            wire:key="coverage-select-municipality"
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
                {{-- wire:ignore es CRÍTICO: el div del mapa lo gestiona Leaflet (inyecta panes/teselas/capas). --}}
                {{-- Sin él, cada re-render de Livewire (los selects `live`, "Load", "Save") morfa este div de --}}
                {{-- vuelta al HTML vacío del servidor y BORRA el mapa (bug: "el mapa desaparece" / la zona no --}}
                {{-- se dibuja). Con wire:ignore, Livewire nunca toca este subárbol. --}}
                <div wire:ignore>
                    <div id="coverage-map" style="height: 70vh; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
                </div>
            </x-filament::section>
        </div>
    </div>

    @assets
        {{-- Leaflet + Geoman vendorizados localmente: cero dependencias externas en runtime (servido desde self). --}}
        {{-- Orden crítico: leaflet.css/js ANTES de geoman y ANTES de dismac-maps.js. --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <link rel="stylesheet" href="{{ asset('vendor/leaflet-geoman/leaflet-geoman.css') }}" />
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        {{-- Teselas solo del tileserver self-host (CERO CDN): sin fallback a un CDN público si no responde. --}}
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
        {{-- Libera el mapa al navegar en SPA (evita "Map container is already initialized" al revisitar). --}}
        <script src="{{ asset('js/dismac-live.js') }}"></script>
        <script src="{{ asset('vendor/leaflet-geoman/leaflet-geoman.min.js') }}"></script>
    @endassets

    @script
    <script>
        const container = document.getElementById('coverage-map');
        let zones = @js($zones);
        const nodes = @js($nodes);
        const zonesLabel = @js(__('Coverage Zones'));
        const nodesLabel = @js(__('Nodes'));

        // Toda la inicialización va dentro de un try/catch: una geometría o un estado inesperado jamás
        // deben tirar el <script> entero y dejar la página en blanco. Si algo falla, se registra y se sigue.
        try {
            if (!container) { throw new Error('coverage-map container missing'); }

            // Guarda anti doble-init: si Leaflet ya montó un mapa en este contenedor (re-ejecución del
            // @script tras una revisita SPA sobre el MISMO nodo), lo liberamos antes de recrearlo. Evita
            // el clásico "Map container is already initialized".
            if (container._dismacMap) {
                try { container._dismacMap.remove(); } catch (e) {}
                container._dismacMap = null;
            }

            // Sesión en vivo: libera el mapa al salir en SPA (wire:navigate) → sin mapas colgados ni
            // "already initialized" al volver. Ver public/js/dismac-live.js (gotcha SPA).
            const live = window.DismacLive
                ? window.DismacLive.session({ el: container, component: $wire.__instance })
                : { onDispose() {}, timeout(ms, fn) { return window.setTimeout(fn, ms); } };

            const map = L.map(container).setView([-16.5, -68.15], 11); // La Paz / Bolivia
            container._dismacMap = map;
            live.onDispose(() => { try { map.remove(); } catch (e) {} container._dismacMap = null; });

            // Teselas solo del tileserver self-host (CERO CDN); si falla, el mapa queda sin fondo (sin fallback público).
            window.DismacMaps.tileLayer(L, {
                url: @js(config('maps.tile_url')),
                fallbackUrl: @js(config('maps.tile_fallback_url')),
                attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
                maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
            })?.addTo(map);

            // Parseo DEFENSIVO de un GeoJSON (string): devuelve el objeto solo si trae coordenadas usables;
            // si es null/vacío/ inválido devuelve null para DEGRADAR (no dibujar) en vez de reventar Leaflet.
            function safeGeo(raw) {
                if (!raw || typeof raw !== 'string') { return null; }
                let gj;
                try { gj = JSON.parse(raw); } catch (e) { return null; }
                if (!gj || typeof gj !== 'object') { return null; }
                const geom = gj.geometry || gj;
                if (!geom || !Array.isArray(geom.coordinates) || geom.coordinates.length === 0) { return null; }
                return gj;
            }

            // fitBounds solo con límites válidos: una geometría degenerada (un punto, NaN) da bounds
            // inválidos y, sin esta guarda, deja el mapa en blanco o lanza.
            function safeFit(layer, opts) {
                try {
                    const b = layer.getBounds();
                    if (b && b.isValid()) { map.fitBounds(b.pad(0.2), opts || {}); }
                } catch (e) {}
            }

            const zonesLayer = L.featureGroup().addTo(map);
            let editLayer = null;

            function renderZones(list) {
                zonesLayer.clearLayers();
                (list || []).forEach((z) => {
                    const g = safeGeo(z.geojson);
                    if (!g) { return; } // zona sin geometría válida: se omite, el mapa sigue vivo
                    try {
                        const layer = L.geoJSON(g, {
                            style: { color: z.active ? '#E2231A' : '#9a9a9a', weight: 2, fillColor: '#E2231A', fillOpacity: 0.12 },
                        }).bindPopup('<strong>' + escapeHtml(z.name) + '</strong>');
                        layer.addTo(zonesLayer);
                    } catch (e) {}
                });
                if (zonesLayer.getLayers().length) {
                    safeFit(zonesLayer);
                }
            }

            // Capa de NODOS de la red (marcadores) — conmutable. Cada marcador enlaza a la ficha 360 del nodo.
            function escapeHtml(value) {
                return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
                }[c]));
            }
            function nodeColor(status) {
                return String(status || '').toLowerCase() === 'active' ? '#16a34a' : '#9a9a9a';
            }
            const nodesLayer = L.featureGroup().addTo(map);
            (nodes || []).forEach((n) => {
                const lat = Number(n.lat);
                const lng = Number(n.lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                    return;
                }
                const marker = L.circleMarker([lat, lng], {
                    radius: 7,
                    color: nodeColor(n.status),
                    fillColor: nodeColor(n.status),
                    fillOpacity: 0.85,
                    weight: 2,
                });
                const title = n.url
                    ? '<a href="' + escapeHtml(n.url) + '"><strong>' + escapeHtml(n.code) + '</strong></a>'
                    : '<strong>' + escapeHtml(n.code) + '</strong>';
                marker.bindPopup(
                    title + '<br>' + escapeHtml(n.name) + '<br>' +
                    '<span class="text-xs text-gray-500">' + escapeHtml(n.type) + ' · ' + escapeHtml(n.status) + '</span>'
                );
                marker.addTo(nodesLayer);
            });

            // Control de capas: el usuario conmuta Zonas de cobertura / Nodos sobre el mismo lienzo.
            L.control.layers(null, {
                [zonesLabel]: zonesLayer,
                [nodesLabel]: nodesLayer,
            }, { collapsed: false }).addTo(map);

            // Pintar las zonas iniciales (tras definir safeFit/escapeHtml). Degrada por zona: una geometría
            // inválida se omite sin tumbar el mapa.
            renderZones(zones);

            // Si no hay zonas con geometría pero sí nodos, encuadra a los nodos para no quedar en el centro vacío.
            if (!zonesLayer.getLayers().length && nodesLayer.getLayers().length) {
                safeFit(nodesLayer);
            }

            try { map.pm.setLang('es'); } catch (e) {}
            try {
                map.pm.addControls({
                    position: 'topleft',
                    drawMarker: false, drawPolyline: false, drawCircle: false, drawCircleMarker: false,
                    drawRectangle: false, drawText: false, cutPolygon: false, rotateMode: false, dragMode: false,
                });
            } catch (e) {}

            function pushDrawn(layer) {
                try { $wire.set('drawnGeoJson', JSON.stringify(layer.toGeoJSON()), true); } catch (e) {}
            }

            map.on('pm:create', (e) => {
                if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} }
                editLayer = e.layer;
                pushDrawn(editLayer);
                editLayer.on('pm:edit', () => pushDrawn(editLayer));
            });

            // Editar una zona existente cargada desde el servidor. Si la geometría viene null/vacía/ inválida,
            // se DEGRADA (no se dibuja nada) sin romper el mapa.
            $wire.on('zone-loaded', (ev) => {
                const raw = Array.isArray(ev) ? ev[0]?.geojson : ev.geojson;
                const gj = safeGeo(raw);
                if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
                if (!gj) { return; }
                try {
                    editLayer = L.geoJSON(gj, { style: { color: '#E2231A', weight: 3 } }).addTo(map);
                    editLayer.eachLayer((l) => {
                        if (l.pm) { l.pm.enable({ allowSelfIntersection: false }); l.on('pm:edit', () => pushDrawn(l)); }
                    });
                    safeFit(editLayer, { animate: true, duration: 0.8 });
                } catch (e) {}
            });

            // Zoom animado al conjunto de zonas del municipio elegido (dropdown Municipio/Ciudad con `live`).
            $wire.on('focus-area', (ev) => {
                const geojsons = Array.isArray(ev) ? ev[0]?.geojsons : ev.geojsons;
                if (!Array.isArray(geojsons) || !geojsons.length) return;
                try {
                    const group = L.featureGroup();
                    geojsons.forEach((raw) => {
                        const gj = safeGeo(raw);
                        if (gj) { try { L.geoJSON(gj).addTo(group); } catch (x) {} }
                    });
                    if (group.getLayers().length) {
                        safeFit(group, { animate: true, duration: 0.8 });
                    }
                } catch (e) {}
            });

            // Municipio vacío (o sin zonas con geometría): re-encuadra todas las zonas del mapa.
            $wire.on('focus-reset', () => {
                if (zonesLayer.getLayers().length) {
                    safeFit(zonesLayer, { animate: true, duration: 0.8 });
                }
            });

            $wire.on('zone-reset', () => {
                if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
            });

            $wire.on('zones-updated', (ev) => {
                const list = Array.isArray(ev) ? ev[0]?.zones : ev.zones;
                if (list) { zones = list; renderZones(zones); }
                if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
            });

            // El mapa necesita recalcular tamaño cuando el contenedor ya está visible. El timer se cancela
            // al liberar la sesión (SPA), así no corre contra un mapa ya destruido.
            live.timeout(250, () => { try { map.invalidateSize(); } catch (e) {} });
        } catch (error) {
            // Último recurso: nunca dejar la página en blanco por un fallo del mapa. Se registra y se sigue.
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('CoverageMap: fallo al inicializar el mapa', error);
            }
        }
    </script>
    @endscript
</x-filament-panels::page>
