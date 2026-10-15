{{-- Cockpit de telemetría "operaciones-primero": a la izquierda el mapa en vivo de la flota sobre --}}
{{-- OpenStreetMap (Leaflet, sin API key) y a la derecha el panel de EXCEPCIONES accionables (alertas sin --}}
{{-- reconocer y vehículos inactivos/ralentí). Un marcador por vehículo, coloreado según se mueva (verde) --}}
{{-- o esté detenido (gris). El refresco es por polling desde el navegador, que vuelve a pedir posiciones --}}
{{-- (`positions()`) y excepciones (`exceptions()`). Clic en un camión o en una fila dibuja su recorrido --}}
{{-- del día (`vehicleTrack`) pegado a calles con OSRM /match y centra el mapa. "Acknowledge" en una alerta --}}
{{-- despacha el Command vía `$wire.acknowledge(id)`. Nada de UUID visible: placa resuelta server-side. --}}
<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Mapa (ocupa 2/3 en escritorio). --}}
        <div class="lg:col-span-2">
            <div wire:ignore>
                <div id="live-fleet-map" style="height: 70vh; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-2">
                    <span style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:#16a34a;"></span>
                    {{ __('Moving') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:#6b7280;"></span>
                    {{ __('Idle') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span style="display:inline-block;width:18px;border-top:3px solid #E2231A;"></span>
                    {{ __('Selected vehicle track') }}
                </span>
            </div>
        </div>

        {{-- Panel de excepciones accionables (ocupa 1/3). Lo llena/actualiza el JS con el polling. --}}
        <div class="lg:col-span-1">
            <div
                wire:ignore
                id="fleet-exceptions"
                class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                style="max-height: 70vh; overflow-y: auto;"
            >
                {{-- Contenido inyectado por JS; este texto solo se ve un instante antes del primer pintado. --}}
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Loading exceptions…') }}</p>
            </div>
        </div>
    </div>

    @assets
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        {{-- Helper de teselas con fallback automático al OSM público si el tileserver self-host no responde. --}}
        <script src="{{ asset('js/dismac-maps.js') }}"></script>
    @endassets

    @script
    <script>
        const el = document.getElementById('live-fleet-map');
        const panel = document.getElementById('fleet-exceptions');
        // Datos iniciales (primitivos) inyectados por el servidor para el primer pintado.
        let positions = @js($positions);
        let exceptions = @js($exceptions);
        // Mapa id→placa para resolver el popup del marcador sin mostrar el UUID (regla: nada de UUID visible).
        const plates = @js($plates);
        // Host de OSRM para pegar la traza del vehículo a calles reales (map matching del GPS).
        const osrmHost = @js(rtrim(config('logistics-telemetry.osrm_host', config('logistics-planning.osrm_host', 'https://router.project-osrm.org')), '/'));

        // Escapa texto para los popups/panel (los datos vienen de la lectura, pero evitamos romper el HTML).
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        const plate = (id) => plates[id] || '—';

        const map = L.map(el).setView([-16.5, -68.15], 11); // Bolivia
        // Teselas con fallback automático: intenta el tileserver self-host (config/maps.php) y, si no
        // responde, baja al OSM público para que el mapa nunca quede gris. Helper compartido dismac-maps.js.
        window.DismacMaps.tileLayer(L, {
            url: @js(config('maps.tile_url')),
            fallbackUrl: @js(config('maps.tile_fallback_url')),
            attribution: @js(config('maps.tile_attribution', '&copy; OpenStreetMap')),
            maxZoom: @js((int) config('maps.tile_max_zoom', 19)),
        }).addTo(map);

        // Capa de marcadores de vehículos (se vacía y se vuelve a llenar en cada refresco).
        const fleetLayer = L.layerGroup().addTo(map);
        // Capa del recorrido del vehículo seleccionado (se reemplaza en cada clic).
        const trackLayer = L.layerGroup().addTo(map);

        // Icono circular coloreado según el estado de movimiento del vehículo.
        const vehicleIcon = (moving) => L.divIcon({
            className: 'fleet-vehicle-marker',
            html: '<div style="background:' + (moving ? '#16a34a' : '#6b7280') + ';width:16px;height:16px;'
                + 'border-radius:9999px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });

        const labelMoving = @js(__('Moving'));
        const labelIdle = @js(__('Idle'));
        const labelSpeed = @js(__('Speed'));
        const labelRecordedAt = @js(__('Recorded at'));
        const labelExceptions = @js(__('Exceptions'));
        const labelUnackAlerts = @js(__('Unacknowledged alerts'));
        const labelIdleVehicles = @js(__('Idle vehicles'));
        const labelAcknowledge = @js(__('Acknowledge'));
        const labelAllClear = @js(__('All clear — no exceptions'));
        const labelIdling = @js(__('Idling'));
        const labelStopped = @js(__('Stopped'));

        // Antigüedad legible a partir de minutos (fallback '—' si no aplica).
        const age = (m) => {
            if (m == null || m < 0) return '—';
            if (m < 1) return @js(__('just now'));
            if (m < 60) return m + @js(__('m'));
            const h = Math.floor(m / 60);
            const r = m % 60;
            return r ? (h + @js(__('h')) + ' ' + r + @js(__('m'))) : (h + @js(__('h')));
        };

        // Clases Tailwind del badge de severidad (se renderiza en JS, sin el helper de badges de Filament).
        const sevClass = (color) => ({
            danger: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
            warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
            info: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
        })[color] || 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300';

        // Dibuja la traza del día del vehículo pegada a calles con OSRM /match; si falla, traza cruda.
        const drawTrack = (points) => {
            trackLayer.clearLayers();
            const latlngs = points.map((p) => [p.lat, p.lng]);
            if (latlngs.length < 2) {
                if (latlngs.length === 1) {
                    map.setView(latlngs[0], Math.max(map.getZoom(), 14));
                }
                return;
            }

            const raw = () => {
                const line = L.polyline(latlngs, { color: '#E2231A', weight: 4, opacity: 0.9 }).addTo(trackLayer);
                try { map.fitBounds(line.getBounds().pad(0.2)); } catch (e) {}
            };

            // OSRM público limita ~100 coordenadas por consulta: submuestreamos conservando extremos.
            const max = 100;
            let pts = latlngs;
            if (latlngs.length > max) {
                const step = (latlngs.length - 1) / (max - 1);
                pts = [];
                for (let i = 0; i < max; i++) {
                    pts.push(latlngs[Math.round(i * step)]);
                }
            }
            const coords = pts.map((p) => p[1] + ',' + p[0]).join(';');
            const radiuses = pts.map(() => '25').join(';');
            const url = osrmHost + '/match/v1/driving/' + coords
                + '?geometries=geojson&overview=full&tidy=true&gaps=ignore&radiuses=' + radiuses;

            fetch(url)
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error('match http ' + r.status))))
                .then((data) => {
                    if (!data || data.code !== 'Ok' || !Array.isArray(data.matchings) || !data.matchings.length) {
                        throw new Error('match sin geometría');
                    }
                    let snapped = [];
                    data.matchings.forEach((m) => {
                        const line = m && m.geometry && m.geometry.coordinates;
                        if (Array.isArray(line)) {
                            snapped = snapped.concat(line.map((c) => [c[1], c[0]]));
                        }
                    });
                    if (snapped.length < 2) {
                        throw new Error('match vacío');
                    }
                    const line = L.polyline(snapped, { color: '#E2231A', weight: 4, opacity: 0.9 }).addTo(trackLayer);
                    try { map.fitBounds(line.getBounds().pad(0.2)); } catch (e) {}
                })
                .catch(() => raw());
        };

        // Drill-down: centra el mapa en la unidad (si hay posición) y dibuja su recorrido del día.
        const focusVehicle = (vehicleId, lat, lng) => {
            if (lat != null && lng != null) {
                map.setView([lat, lng], Math.max(map.getZoom(), 14));
            }
            if (vehicleId) {
                $wire.vehicleTrack(vehicleId).then((pings) => drawTrack(pings || []));
            }
        };

        // Repinta todos los marcadores de la flota a partir de las posiciones actuales.
        const render = () => {
            fleetLayer.clearLayers();
            (positions || []).forEach((p) => {
                const marker = L.marker([p.lat, p.lng], { icon: vehicleIcon(p.moving) })
                    .bindPopup('<strong>' + esc(plate(p.vehicle_id)) + '</strong><br>'
                        + esc(p.moving ? labelMoving : labelIdle) + '<br>'
                        + esc(labelSpeed) + ': ' + esc(p.speed_kph != null ? p.speed_kph + ' km/h' : '—') + '<br>'
                        + esc(labelRecordedAt) + ': ' + esc(p.recorded_at))
                    .addTo(fleetLayer);
                // Clic en el camión → su recorrido del día, pedido al lado de lectura CQRS.
                marker.on('click', () => focusVehicle(p.vehicle_id, p.lat, p.lng));
            });
        };

        // Repinta el panel de excepciones (alertas sin reconocer + vehículos inactivos).
        const renderExceptions = () => {
            const alerts = (exceptions && exceptions.alerts) || [];
            const idle = (exceptions && exceptions.idle) || [];
            let html = '';

            html += '<h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">' + esc(labelExceptions) + '</h3>';

            if (!alerts.length && !idle.length) {
                html += '<p class="mt-3 text-sm text-gray-500 dark:text-gray-400">' + esc(labelAllClear) + '</p>';
                panel.innerHTML = html;
                return;
            }

            // Alertas de telemetría sin reconocer (posibles mal usos) con acción inline Acknowledge.
            html += '<p class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-400">'
                + esc(labelUnackAlerts) + ' (' + alerts.length + ')</p>';
            html += '<ul class="mt-1 divide-y divide-gray-100 dark:divide-white/5">';
            alerts.forEach((a, i) => {
                html += '<li class="flex items-start justify-between gap-2 py-2">'
                    + '<button type="button" data-focus-alert="' + i + '" class="min-w-0 flex-1 text-left">'
                    + '<span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">' + esc(a.vehicle) + '</span>'
                    + '<span class="mt-0.5 inline-flex items-center gap-1">'
                    + '<span class="rounded px-1.5 py-0.5 text-[10px] font-semibold ' + sevClass(a.severity_color) + '">' + esc(a.severity_label) + '</span>'
                    + '<span class="text-xs text-gray-500 dark:text-gray-400">' + esc(a.type) + '</span>'
                    + '</span>'
                    + '<span class="mt-0.5 block text-[11px] text-gray-400">' + esc(age(a.age_minutes)) + '</span>'
                    + '</button>'
                    + '<button type="button" data-ack="' + esc(a.id) + '" class="shrink-0 rounded-md bg-green-600 px-2 py-1 text-xs font-medium text-white hover:bg-green-700">'
                    + esc(labelAcknowledge) + '</button>'
                    + '</li>';
            });
            html += '</ul>';

            // Vehículos inactivos (ralentí o detenidos): drill-down al track, sin Command directo.
            html += '<p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-400">'
                + esc(labelIdleVehicles) + ' (' + idle.length + ')</p>';
            html += '<ul class="mt-1 divide-y divide-gray-100 dark:divide-white/5">';
            idle.forEach((v, i) => {
                const state = v.idling ? labelIdling : labelStopped;
                const dot = v.idling ? '#f59e0b' : '#6b7280';
                html += '<li class="py-2">'
                    + '<button type="button" data-focus-idle="' + i + '" class="flex w-full items-center justify-between gap-2 text-left">'
                    + '<span class="min-w-0 flex-1">'
                    + '<span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">' + esc(v.vehicle) + '</span>'
                    + '<span class="mt-0.5 inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">'
                    + '<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;background:' + dot + ';"></span>'
                    + esc(state) + '</span>'
                    + '</span>'
                    + '<span class="shrink-0 text-[11px] text-gray-400">' + esc(age(v.age_minutes)) + '</span>'
                    + '</button>'
                    + '</li>';
            });
            html += '</ul>';

            panel.innerHTML = html;

            // Enlaza los handlers tras reemplazar el HTML (no hay delegación global para no fugar listeners).
            panel.querySelectorAll('[data-ack]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    btn.disabled = true;
                    $wire.acknowledge(btn.getAttribute('data-ack')).then((fresh) => {
                        exceptions = fresh || { alerts: [], idle: [] };
                        renderExceptions();
                    });
                });
            });
            panel.querySelectorAll('[data-focus-alert]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const a = alerts[parseInt(btn.getAttribute('data-focus-alert'), 10)];
                    if (a) focusVehicle(a.vehicle_id, a.lat, a.lng);
                });
            });
            panel.querySelectorAll('[data-focus-idle]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const v = idle[parseInt(btn.getAttribute('data-focus-idle'), 10)];
                    if (v) focusVehicle(v.vehicle_id, v.lat, v.lng);
                });
            });
        };

        render();
        renderExceptions();

        // El contenedor puede estar oculto al crearse; recalcular tamaño cuando ya es visible.
        setTimeout(() => map.invalidateSize(), 300);

        // Polling: cada 10 s se vuelven a pedir posiciones y excepciones al servidor y se repinta todo.
        setInterval(() => {
            $wire.positions().then((fresh) => {
                positions = fresh || [];
                render();
            });
            $wire.exceptions().then((fresh) => {
                exceptions = fresh || { alerts: [], idle: [] };
                renderExceptions();
            });
        }, 10000);
    </script>
    @endscript
</x-filament-panels::page>
