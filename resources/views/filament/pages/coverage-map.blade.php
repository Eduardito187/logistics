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

    {{-- Isla de datos: todo lo que el mapa necesita, serializado aquí dentro del componente. Se re-renderiza
         (y por tanto se refresca) en cada render de Livewire y en cada navegación SPA, así el init siempre lee
         datos frescos. Va DENTRO de <x-filament-panels::page> (hijo del root único), no es un segundo root.
         No usamos @@js/@@script: en una Filament Page rompen el detector de root único de Livewire. --}}
    <div id="coverage-map-data" hidden aria-hidden="true">@json([
        'zones' => $zones,
        'nodes' => $nodes,
        'zonesLabel' => __('Coverage Zones'),
        'nodesLabel' => __('Nodes'),
        'tileUrl' => config('maps.tile_url'),
        'tileFallbackUrl' => config('maps.tile_fallback_url'),
        'tileAttribution' => config('maps.tile_attribution', '&copy; OpenStreetMap'),
        'tileMaxZoom' => (int) config('maps.tile_max_zoom', 19),
    ])</div>

    {{-- Assets vía stacks del layout (NO @@assets/@@script, que inyectan nodos raíz y rompen el root único de
         la Page). @@push no renderiza inline: va a @@stack del layout, así la Page conserva UN SOLO root. --}}
    @push('styles')
        {{-- Leaflet + Geoman vendorizados localmente: cero dependencias externas en runtime (CERO CDN). --}}
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <link rel="stylesheet" href="{{ asset('vendor/leaflet-geoman/leaflet-geoman.css') }}" />
    @endpush

    @push('scripts')
        {{-- Orden crítico: leaflet ANTES de geoman y de los helpers. Servidos desde self (CERO CDN). --}}
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
        {{-- Libera el mapa al navegar en SPA (evita "Map container is already initialized" al revisitar). --}}
        <script src="{{ asset('js/dismac-live.js') }}"></script>
        <script src="{{ asset('vendor/leaflet-geoman/leaflet-geoman.min.js') }}"></script>
        <script>
        (function () {
            'use strict';

            // Inicializa (o re-inicializa) el mapa de cobertura. Se llama en la carga inicial y en cada
            // navegación SPA (livewire:navigated), porque la pila de scripts del layout NO vuelve a
            // ejecutarse en una navegación wire:navigate. Es idempotente: la guarda anti doble-init
            // libera el mapa previo antes de recrearlo.
            function initCoverageMap() {
                const container = document.getElementById('coverage-map');
                if (!container) { return; } // navegamos a otra página: no hay mapa que montar

                // Toda la init va en try/catch: una geometría o un estado inesperado jamás deben tirar el
                // script ni dejar la página en blanco.
                try {
                    // Datos frescos del render actual (isla de datos re-renderizada por Livewire).
                    let data = {};
                    try {
                        const dataEl = document.getElementById('coverage-map-data');
                        data = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};
                    } catch (e) { data = {}; }

                    let zones = Array.isArray(data.zones) ? data.zones : [];
                    const nodes = Array.isArray(data.nodes) ? data.nodes : [];
                    const zonesLabel = data.zonesLabel || 'Coverage Zones';
                    const nodesLabel = data.nodesLabel || 'Nodes';

                    // En un script normal del layout no existe el mágico `$wire` de Livewire: resolvemos el
                    // componente desde el DOM (el root de la Page lleva wire:id) y usamos su proxy $wire.
                    const rootEl = container.closest('[wire\\:id]');
                    const component = (rootEl && window.Livewire)
                        ? window.Livewire.find(rootEl.getAttribute('wire:id'))
                        : null;
                    const wire = component ? component.$wire : null;

                    // Guarda anti doble-init: si Leaflet ya montó un mapa en este contenedor, lo liberamos
                    // antes de recrearlo. Evita el clásico "Map container is already initialized".
                    if (container._dismacMap) {
                        try { container._dismacMap.remove(); } catch (e) {}
                        container._dismacMap = null;
                    }

                    // Sesión en vivo: libera el mapa al salir en SPA (livewire:navigating) → sin mapas
                    // colgados ni "already initialized" al volver. Ver public/js/dismac-live.js.
                    const live = window.DismacLive
                        ? window.DismacLive.session({ el: container, component: component })
                        : { onDispose() {}, timeout(ms, fn) { return window.setTimeout(fn, ms); } };

                    const map = L.map(container).setView([-16.5, -68.15], 11); // La Paz / Bolivia
                    container._dismacMap = map;
                    live.onDispose(() => { try { map.remove(); } catch (e) {} container._dismacMap = null; });

                    // Teselas solo del tileserver self-host (CERO CDN); si no hay URL, el mapa queda sin fondo.
                    const tiles = window.DismacMaps ? window.DismacMaps.tileLayer(L, {
                        url: data.tileUrl || null,
                        fallbackUrl: data.tileFallbackUrl || null,
                        attribution: data.tileAttribution || '&copy; OpenStreetMap',
                        maxZoom: typeof data.tileMaxZoom === 'number' ? data.tileMaxZoom : 19,
                    }) : null;
                    if (tiles) { tiles.addTo(map); }

                    // Parseo DEFENSIVO de un GeoJSON (string): devuelve el objeto solo si trae coordenadas
                    // usables; si es null/vacío/ inválido devuelve null para DEGRADAR (no dibujar) en vez de
                    // reventar Leaflet.
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

                    function escapeHtml(value) {
                        return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
                            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
                        }[c]));
                    }
                    function nodeColor(status) {
                        return String(status || '').toLowerCase() === 'active' ? '#16a34a' : '#9a9a9a';
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

                    // Capa de NODOS de la red (marcadores) — conmutable. Cada marcador enlaza a la ficha 360.
                    const nodesLayer = L.featureGroup().addTo(map);
                    (nodes || []).forEach((n) => {
                        const lat = Number(n.lat);
                        const lng = Number(n.lng);
                        if (!Number.isFinite(lat) || !Number.isFinite(lng)) { return; }
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

                    // Control de capas: conmuta Zonas de cobertura / Nodos sobre el mismo lienzo.
                    L.control.layers(null, {
                        [zonesLabel]: zonesLayer,
                        [nodesLabel]: nodesLayer,
                    }, { collapsed: false }).addTo(map);

                    // Pintar las zonas iniciales. Degrada por zona: geometría inválida se omite sin romper.
                    renderZones(zones);

                    // Sin zonas con geometría pero con nodos: encuadra a los nodos, no al centro vacío.
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
                        if (!wire) { return; }
                        try { wire.set('drawnGeoJson', JSON.stringify(layer.toGeoJSON()), true); } catch (e) {}
                    }

                    map.on('pm:create', (e) => {
                        if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} }
                        editLayer = e.layer;
                        pushDrawn(editLayer);
                        editLayer.on('pm:edit', () => pushDrawn(editLayer));
                    });

                    // Los eventos del servidor solo se escuchan si tenemos el componente Livewire resuelto.
                    if (wire) {
                        // Editar una zona existente cargada desde el servidor. Geometría null/vacía/ inválida
                        // → DEGRADA (no dibuja nada) sin romper el mapa.
                        wire.on('zone-loaded', (ev) => {
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

                        // Zoom animado al conjunto de zonas del municipio elegido (dropdown con `live`).
                        wire.on('focus-area', (ev) => {
                            const geojsons = Array.isArray(ev) ? ev[0]?.geojsons : ev.geojsons;
                            if (!Array.isArray(geojsons) || !geojsons.length) { return; }
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
                        wire.on('focus-reset', () => {
                            if (zonesLayer.getLayers().length) {
                                safeFit(zonesLayer, { animate: true, duration: 0.8 });
                            }
                        });

                        wire.on('zone-reset', () => {
                            if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
                        });

                        wire.on('zones-updated', (ev) => {
                            const list = Array.isArray(ev) ? ev[0]?.zones : ev.zones;
                            if (list) { zones = list; renderZones(zones); }
                            if (editLayer) { try { map.removeLayer(editLayer); } catch (x) {} editLayer = null; }
                        });
                    }

                    // Recalcular tamaño cuando el contenedor ya está visible. El timer se cancela al liberar
                    // la sesión (SPA), así no corre contra un mapa ya destruido.
                    live.timeout(250, () => { try { map.invalidateSize(); } catch (e) {} });
                } catch (error) {
                    // Último recurso: nunca dejar la página en blanco por un fallo del mapa.
                    if (window.console && typeof window.console.error === 'function') {
                        window.console.error('CoverageMap: fallo al inicializar el mapa', error);
                    }
                }
            }

            // Registrar el disparador UNA sola vez, aunque la pila de scripts se re-ejecute en una navegación.
            // `livewire:navigated` se dispara tanto en la carga inicial del panel SPA como tras cada
            // navegación wire:navigate, así el mapa se (re)monta siempre que se entra a esta página.
            if (!window.__coverageMapBooted) {
                window.__coverageMapBooted = true;
                document.addEventListener('livewire:navigated', initCoverageMap);
            }
        })();
        </script>
    @endpush
</x-filament-panels::page>
