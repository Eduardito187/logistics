{{-- Mapa en vivo de la flota sobre OpenStreetMap (Leaflet, sin API key), siguiendo el patrón del mapa --}}
{{-- de ruta de execution. Un marcador por vehículo, coloreado según se mueva (verde) o esté detenido --}}
{{-- (gris). El popup muestra placa/estado, velocidad e instante. El refresco es por polling desde el --}}
{{-- navegador, que vuelve a pedir las posiciones al método Livewire `positions()`. Al hacer clic en un --}}
{{-- camión se pide su recorrido del día (`vehicleTrack`) y se dibuja pegado a calles con OSRM /match. --}}
<x-filament-panels::page>
    <div>
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

    @assets
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @endassets

    @script
    <script>
        const el = document.getElementById('live-fleet-map');
        // Posiciones iniciales (primitivos) inyectadas por el servidor para el primer pintado.
        let positions = @js($positions);
        // Host de OSRM para pegar la traza del vehículo a calles reales (map matching del GPS).
        const osrmHost = @js(rtrim(config('logistics-telemetry.osrm_host', config('logistics-planning.osrm_host', 'https://router.project-osrm.org')), '/'));

        // Escapa texto para los popups (los datos vienen de la lectura, pero evitamos romper el HTML).
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        const map = L.map(el).setView([-16.5, -68.15], 11); // Bolivia
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19,
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

        // Repinta todos los marcadores de la flota a partir de las posiciones actuales.
        const render = () => {
            fleetLayer.clearLayers();
            (positions || []).forEach((p) => {
                const marker = L.marker([p.lat, p.lng], { icon: vehicleIcon(p.moving) })
                    .bindPopup('<strong>' + esc(p.vehicle_id) + '</strong><br>'
                        + esc(p.moving ? labelMoving : labelIdle) + '<br>'
                        + esc(labelSpeed) + ': ' + esc(p.speed_kph != null ? p.speed_kph + ' km/h' : '—') + '<br>'
                        + esc(labelRecordedAt) + ': ' + esc(p.recorded_at))
                    .addTo(fleetLayer);
                // Clic en el camión → su recorrido del día, pedido al lado de lectura CQRS.
                marker.on('click', () => {
                    $wire.vehicleTrack(p.vehicle_id).then((pings) => drawTrack(pings || []));
                });
            });
        };

        render();

        // El contenedor puede estar oculto al crearse; recalcular tamaño cuando ya es visible.
        setTimeout(() => map.invalidateSize(), 300);

        // Polling: cada 10 s se vuelve a pedir la posición de la flota al método Livewire y se repinta.
        setInterval(() => {
            $wire.positions().then((fresh) => {
                positions = fresh || [];
                render();
            });
        }, 10000);
    </script>
    @endscript
</x-filament-panels::page>
