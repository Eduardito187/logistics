<script setup>
// Mapa de ruta reutilizable sobre OpenStreetMap (Leaflet, sin API key). Dibuja las paradas numeradas,
// el trazado planificado (azul punteado) y el recorrido real (rojo Dismac). Carga Leaflet desde CDN una
// sola vez. Expone focusStop(seq) para que la página de detalle centre/abra el marcador de una parada
// al hacer clic en su tarjeta (mismo patrón que en Filament, pero en Vue y sin eventos globales).
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    stops: { type: Array, default: () => [] }, // [{ seq, lat, lng, order, customer, status }]
    planned: { type: Array, default: () => [] }, // [[lat, lng], ...] paradas en orden (para trazar)
    actual: { type: Array, default: () => [] }, // [[lat, lng], ...] recorrido real (GPS)
    // Geometría ya calculada de la ruta planificada ([[lat,lng], ...]); tiene prioridad sobre OSRM.
    geometry: { type: Array, default: () => [] },
    // Host de OSRM (ruteo OpenStreetMap, sin API key) para trazar la planificada siguiendo calles.
    osrmHost: { type: String, default: 'https://router.project-osrm.org' },
    // URL de teselas (patrón Leaflet {z}/{x}/{y}). Si no se pasa, se resuelve desde la config de mapas
    // compartida por Inertia (config/maps.php) y, en última instancia, el tile público de OSM.
    tileUrl: { type: String, default: '' },
});

// Config de teselas compartida por Inertia (HandleInertiaRequests → 'maps'). Permite usar el tileserver
// OSM self-host del proyecto sin una URL fija en el componente. Degrada con gracia si no está presente.
const TILE_FALLBACK = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const mapsConfig = () => (usePage().props?.maps ?? {});
const resolveTileUrl = () => {
    const cfg = mapsConfig();
    return props.tileUrl || cfg.tileUrl || cfg.tileFallbackUrl || TILE_FALLBACK;
};

const mapEl = ref(null);
let map = null;
const markersBySeq = {};

const LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
const LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

function loadLeaflet() {
    return new Promise((resolve, reject) => {
        if (window.L) {
            resolve(window.L);
            return;
        }
        if (!document.querySelector(`link[href="${LEAFLET_CSS}"]`)) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = LEAFLET_CSS;
            document.head.appendChild(link);
        }
        const existing = document.querySelector(`script[src="${LEAFLET_JS}"]`);
        if (existing) {
            existing.addEventListener('load', () => resolve(window.L));
            existing.addEventListener('error', reject);
            return;
        }
        const script = document.createElement('script');
        script.src = LEAFLET_JS;
        script.onload = () => resolve(window.L);
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

const esc = (v) => String(v ?? '');

function render(L) {
    map = L.map(mapEl.value).setView([-16.5, -68.15], 11); // Bolivia
    const cfg = mapsConfig();
    L.tileLayer(resolveTileUrl(), {
        attribution: cfg.tileAttribution || '&copy; OpenStreetMap',
        maxZoom: cfg.tileMaxZoom || 19,
    }).addTo(map);

    const group = L.featureGroup().addTo(map);
    const plannedStyle = { color: '#2563eb', weight: 3, opacity: 0.85, dashArray: '6,8' };
    const fit = () => {
        if (group.getLayers().length) {
            try { map.fitBounds(group.getBounds().pad(0.2)); } catch (e) { /* vacío */ }
        }
    };

    // Ruta planificada: 1) geometría ya calculada (server-side); 2) OSRM siguiendo calles reales;
    // 3) línea recta de respaldo si OSRM falla. Así SIEMPRE se ve la ruta planificada.
    const drawPlannedStraight = () => {
        if (props.planned.length > 1) {
            L.polyline(props.planned, plannedStyle).addTo(group);
            fit();
        }
    };
    if (props.geometry.length > 1) {
        L.polyline(props.geometry, plannedStyle).addTo(group);
    } else if (props.planned.length >= 2) {
        const coords = props.planned.map((p) => p[1] + ',' + p[0]).join(';');
        const url = props.osrmHost.replace(/\/+$/, '') + '/route/v1/driving/' + coords + '?overview=full&geometries=geojson';
        fetch(url)
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('osrm http'))))
            .then((data) => {
                const line = data && data.routes && data.routes[0] && data.routes[0].geometry
                    && data.routes[0].geometry.coordinates;
                if (!Array.isArray(line) || line.length < 2) {
                    throw new Error('osrm sin geometría');
                }
                // GeoJSON entrega [lng, lat]; Leaflet dibuja con [lat, lng].
                L.polyline(line.map((c) => [c[1], c[0]]), plannedStyle).addTo(group);
                fit();
            })
            .catch(() => drawPlannedStraight());
    }

    if (props.actual.length > 1) {
        L.polyline(props.actual, { color: '#E2231A', weight: 4, opacity: 0.9 }).addTo(group);
    }

    props.stops.forEach((s) => {
        const icon = L.divIcon({
            className: 'route-stop-marker',
            html: `<div style="background:#2563eb;color:#fff;border-radius:9999px;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)">${esc(s.seq)}</div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13],
        });
        const marker = L.marker([s.lat, s.lng], { icon })
            .bindPopup(`<strong>#${esc(s.seq)} · ${esc(s.order)}</strong><br>${esc(s.customer)}<br>${esc(s.status)}`)
            .addTo(group);
        markersBySeq[String(s.seq)] = marker;
    });

    fit();
    setTimeout(() => map && map.invalidateSize(), 300);
}

// Centra y abre el marcador de una parada. La llama el padre al hacer clic en una tarjeta de parada.
function focusStop(seq) {
    const marker = markersBySeq[String(seq)];
    if (!marker || !map) {
        return false;
    }
    map.invalidateSize();
    map.setView(marker.getLatLng(), Math.max(map.getZoom(), 15), { animate: true });
    marker.openPopup();
    mapEl.value.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
}

defineExpose({ focusStop });

onMounted(async () => {
    try {
        const L = await loadLeaflet();
        render(L);
    } catch (e) {
        // Si Leaflet no carga (red), el contenedor queda vacío; no rompe la página.
    }
});

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div ref="mapEl" class="h-[60vh] w-full rounded-lg" style="z-index: 0"></div>
</template>
