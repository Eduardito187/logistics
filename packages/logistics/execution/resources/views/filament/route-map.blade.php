{{-- Mapa de la ruta del camión sobre OpenStreetMap (Leaflet, sin API key), siguiendo el patrón del --}}
{{-- mapa de coberturas. Muestra las paradas numeradas en orden, la línea planificada (azul) siguiendo --}}
{{-- calles reales —dibujada desde la geometría ya PERSISTIDA por Planning, con respaldo a OSRM del --}}
{{-- navegador y, en última instancia, línea recta— y el recorrido real del camión (rojo Dismac) pegado --}}
{{-- a calles con OSRM /match (map matching), para comparar "por dónde fue" vs "por dónde debía ir". --}}
<div>
    <div wire:ignore>
        <div id="{{ $mapId }}" style="height: 60vh; width: 100%; border-radius: 0.5rem; z-index: 0;"></div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
        <span class="inline-flex items-center gap-2">
            <span style="display:inline-block;width:18px;border-top:3px solid #2563eb;"></span>
            {{ __('Planned route') }}
        </span>
        <span class="inline-flex items-center gap-2">
            <span style="display:inline-block;width:18px;border-top:3px solid #E2231A;"></span>
            {{ __('Actual route (truck GPS)') }}
        </span>
        @if (empty($stops) && empty($actual))
            <span>{{ __('No geolocated stops to display on the map.') }}</span>
        @endif
    </div>

    @assets
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @endassets

    @script
    <script>
        const el = document.getElementById(@js($mapId));
        const stops = @js($stops);
        // Geometría de la línea planificada ya calculada y persistida por Planning (sigue calles). Si viene
        // con puntos, se dibuja directo (sin tocar OSRM); si no, se cae al OSRM del navegador y luego recta.
        const plannedGeometry = @js($plannedGeometry);
        const planned = @js($planned);
        const actual = @js($actual);
        // Host de OSRM (ruteador de OpenStreetMap): respaldo de la línea planificada y map matching del GPS.
        const osrmHost = @js(rtrim(config('logistics-planning.osrm_host', 'https://router.project-osrm.org'), '/'));

        // Escapa texto para los popups (los datos vienen del admin, pero evitamos romper el HTML).
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        const map = L.map(el).setView([-16.5, -68.15], 11); // Bolivia
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19,
        }).addTo(map);

        const group = L.featureGroup().addTo(map);
        // Línea planificada que sigue calles reales (geometría persistida u OSRM): azul sólida.
        const plannedStyle = { color: '#2563eb', weight: 4, opacity: 0.85 };
        // Respaldo de línea recta entre paradas (ni geometría persistida ni OSRM): azul punteada, para que
        // se note a simple vista que es una aproximación y no el trazado real por calles.
        const plannedStraightStyle = { color: '#2563eb', weight: 3, opacity: 0.7, dashArray: '6,8' };
        // Recorrido real del camión (GPS): rojo Dismac sólido.
        const actualStyle = { color: '#E2231A', weight: 4, opacity: 0.9 };

        // Reajusta el encuadre para que entren todas las capas (se llama de nuevo al llegar respuestas OSRM).
        const fit = () => {
            if (group.getLayers().length) {
                try { map.fitBounds(group.getBounds().pad(0.2)); } catch (e) {}
            }
        };

        // Submuestrea una traza a lo sumo a `max` puntos, conservando extremos y repartiendo el resto de
        // forma pareja. OSRM público limita las coordenadas por consulta (~100); así una traza GPS larga no
        // rebota con 400 y el cálculo se mantiene liviano.
        const downsample = (arr, max) => {
            if (arr.length <= max) {
                return arr;
            }
            const step = (arr.length - 1) / (max - 1);
            const out = [];
            for (let i = 0; i < max; i++) {
                out.push(arr[Math.round(i * step)]);
            }
            return out;
        };

        // Trazado planificado. Orden de preferencia:
        //  1) geometría ya PERSISTIDA por Planning (sigue calles): se dibuja directo, sin red.
        //  2) si no hay persistida, se consulta a OSRM /route desde el navegador (comportamiento previo).
        //  3) si OSRM falla, línea recta punteada entre paradas, para no quedarse sin trazado.
        const drawPlannedStraight = () => {
            if (planned.length > 1) {
                L.polyline(planned, plannedStraightStyle).addTo(group);
                fit();
            }
        };

        const drawPlannedFromOsrm = () => {
            if (planned.length < 2) {
                return;
            }
            // OSRM espera "lng,lat" separados por ";"; nuestras paradas vienen como [lat, lng].
            const coords = planned.map((p) => p[1] + ',' + p[0]).join(';');
            const url = osrmHost + '/route/v1/driving/' + coords + '?overview=full&geometries=geojson';

            fetch(url)
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error('osrm http ' + r.status))))
                .then((data) => {
                    const geometry = data && data.code === 'Ok' && data.routes && data.routes[0]
                        && data.routes[0].geometry;
                    const line = geometry && geometry.coordinates;
                    if (!Array.isArray(line) || line.length < 2) {
                        throw new Error('osrm sin geometría');
                    }
                    // GeoJSON entrega [lng, lat]; Leaflet dibuja con [lat, lng].
                    const latlngs = line.map((c) => [c[1], c[0]]);
                    L.polyline(latlngs, plannedStyle).addTo(group);
                    fit();
                })
                .catch(() => drawPlannedStraight());
        };

        const drawPlanned = () => {
            if (plannedGeometry.length > 1) {
                L.polyline(plannedGeometry, plannedStyle).addTo(group);
                fit();
                return;
            }
            drawPlannedFromOsrm();
        };

        drawPlanned();

        // Recorrido real del camión (GPS de los escaneos IN_TRANSIT). Los breadcrumbs vienen espaciados y en
        // línea recta entre sí, así que cruzan manzanas; se PEGAN a calles con OSRM /match (map matching).
        // Si /match falla (red, timeout, respuesta inválida), se dibuja la traza cruda como respaldo.
        const drawActualRaw = () => {
            if (actual.length > 1) {
                L.polyline(actual, actualStyle).addTo(group);
                fit();
            }
        };

        const drawActualSnapped = () => {
            if (actual.length < 2) {
                return;
            }
            const pts = downsample(actual, 100);
            const coords = pts.map((p) => p[1] + ',' + p[0]).join(';');
            // radiuses holgado (25 m) por el ruido del GPS urbano; tidy + gaps=ignore limpian la traza.
            const radiuses = pts.map(() => '25').join(';');
            const url = osrmHost + '/match/v1/driving/' + coords
                + '?geometries=geojson&overview=full&tidy=true&gaps=ignore&radiuses=' + radiuses;

            fetch(url)
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error('match http ' + r.status))))
                .then((data) => {
                    if (!data || data.code !== 'Ok' || !Array.isArray(data.matchings) || !data.matchings.length) {
                        throw new Error('match sin geometría');
                    }
                    // Un match puede partirse en varios tramos; se concatenan en orden. GeoJSON: [lng, lat].
                    let latlngs = [];
                    data.matchings.forEach((m) => {
                        const line = m && m.geometry && m.geometry.coordinates;
                        if (Array.isArray(line)) {
                            latlngs = latlngs.concat(line.map((c) => [c[1], c[0]]));
                        }
                    });
                    if (latlngs.length < 2) {
                        throw new Error('match vacío');
                    }
                    L.polyline(latlngs, actualStyle).addTo(group);
                    fit();
                })
                .catch(() => drawActualRaw());
        };

        drawActualSnapped();

        // Marcadores numerados de cada parada, en orden de reparto. Se guardan por secuencia para poder
        // centrarlos desde las tarjetas de parada (acción "Ver en el mapa").
        const markersBySeq = {};
        stops.forEach((s) => {
            const icon = L.divIcon({
                className: 'route-stop-marker',
                html: '<div style="background:#2563eb;color:#fff;border-radius:9999px;width:26px;height:26px;'
                    + 'display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;'
                    + 'border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)">' + esc(s.seq) + '</div>',
                iconSize: [26, 26],
                iconAnchor: [13, 13],
            });
            const marker = L.marker([s.lat, s.lng], { icon })
                .bindPopup('<strong>#' + esc(s.seq) + ' · ' + esc(s.order) + '</strong><br>'
                    + esc(s.customer) + '<br>' + esc(s.status))
                .addTo(group);
            markersBySeq[String(s.seq)] = marker;
        });

        fit();

        // El contenedor puede estar oculto al crearse; recalcular tamaño cuando ya es visible.
        setTimeout(() => map.invalidateSize(), 300);

        // Centra y abre el marcador de una parada. Se expone como función global para que, al hacer clic en
        // toda la tarjeta de una parada (abajo), el mapa haga zoom a su punto y abra su globo. Hay un solo
        // mapa por página (vista de una ruta), así que una única función global basta.
        const focusStop = (seq) => {
            const marker = markersBySeq[String(seq)];
            if (!marker) {
                return;
            }
            map.invalidateSize();
            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 15), { animate: true });
            marker.openPopup();
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };

        window.focusRouteStop = focusStop;
    </script>
    @endscript
</div>
