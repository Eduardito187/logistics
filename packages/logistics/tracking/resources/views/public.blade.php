{{-- Página pública de rastreo por token: la abre el cliente desde el enlace compartido. Copy en --}}
{{-- español (cliente boliviano), sin PII. Mapa con Leaflet + OpenStreetMap (sin API key). --}}
@php
    /** @var array{status:string,remaining_stops:int,route_position:?int,eta:?string,last_position:?array{lat:float,lng:float,reported_at:string},position_is_live:?bool,stops_message:string} $tracking */
    /** @var list<array{label:string,status:string,status_message:string}> $units */

    // Etiqueta legible del estado (español, copy de cliente).
    $statusLabels = [
        'REGISTERED' => 'Registrado',
        'PREPARED' => 'Preparado',
        'ON_THE_WAY' => 'En camino',
        'ARRIVING' => 'Llegando',
        'DELIVERED' => 'Entregado',
        'FAILED' => 'No entregado',
    ];
    $status = $tracking['status'];
    $statusLabel = $statusLabels[$status] ?? $status;
    $position = $tracking['last_position'] ?? null;
    $isLive = $tracking['position_is_live'] ?? null;

    // Progreso visual aproximado por estado, para la barra de etapas.
    $stages = ['REGISTERED', 'PREPARED', 'ON_THE_WAY', 'ARRIVING', 'DELIVERED'];
    $currentIndex = array_search($status === 'FAILED' ? 'ARRIVING' : $status, $stages, true);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Seguimiento de tu pedido</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root {
            --dismac-red: #E2231A;
            --ink: #1f2933;
            --muted: #6b7280;
            --line: #e5e7eb;
            --bg: #f3f4f6;
            --card: #ffffff;
            --ok: #047857;
            --warn: #b45309;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--ink);
            line-height: 1.5;
        }
        .wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
        header { display: flex; align-items: center; gap: 10px; padding: 8px 0 16px; }
        .brand { font-weight: 800; color: var(--dismac-red); letter-spacing: .5px; font-size: 20px; }
        .card {
            background: var(--card); border: 1px solid var(--line);
            border-radius: 14px; padding: 20px; margin-bottom: 16px;
        }
        .status-badge {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            font-size: 13px; font-weight: 700; background: #eef2ff; color: #3730a3;
        }
        .status-badge.delivered { background: #ecfdf5; color: var(--ok); }
        .status-badge.failed { background: #fef2f2; color: var(--dismac-red); }
        .headline { font-size: 22px; font-weight: 700; margin: 12px 0 4px; }
        .eta { color: var(--muted); font-size: 14px; }
        .stages { display: flex; gap: 6px; margin-top: 18px; }
        .stage { flex: 1; height: 6px; border-radius: 999px; background: var(--line); }
        .stage.done { background: var(--dismac-red); }
        #map { height: 320px; width: 100%; border-radius: 12px; z-index: 0; }
        .map-meta { margin-top: 10px; font-size: 13px; color: var(--muted); }
        .live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%;
            background: var(--ok); margin-right: 6px; vertical-align: middle; }
        .live-dot.stale { background: var(--warn); }
        h2 { font-size: 15px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin: 0 0 12px; }
        ul.units { list-style: none; margin: 0; padding: 0; }
        ul.units li { display: flex; justify-content: space-between; gap: 12px;
            padding: 10px 0; border-bottom: 1px solid var(--line); }
        ul.units li:last-child { border-bottom: 0; }
        .unit-label { font-weight: 600; }
        .unit-status { color: var(--muted); font-size: 14px; text-align: right; }
        footer { text-align: center; color: var(--muted); font-size: 12px; padding: 8px 0 24px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <span class="brand">DISMAC</span>
        <span style="color:var(--muted);font-size:14px;">Seguimiento de tu pedido</span>
    </header>

    <div class="card">
        <span class="status-badge {{ $status === 'DELIVERED' ? 'delivered' : '' }} {{ $status === 'FAILED' ? 'failed' : '' }}">
            {{ $statusLabel }}
        </span>
        <div class="headline">{{ $tracking['stops_message'] }}</div>
        @if (!empty($tracking['eta']))
            <div class="eta">Llegada estimada: <strong id="eta"></strong></div>
        @endif

        <div class="stages" aria-hidden="true">
            @foreach ($stages as $i => $stage)
                <div class="stage {{ $i <= $currentIndex && $status !== 'FAILED' ? 'done' : '' }}"></div>
            @endforeach
        </div>
    </div>

    @if ($position)
        <div class="card">
            <h2>Ubicación del camión</h2>
            <div id="map"></div>
            <div class="map-meta">
                <span class="live-dot {{ $isLive ? '' : 'stale' }}"></span>
                <span id="position-meta">{{ $isLive ? 'En vivo' : 'Última posición conocida' }}</span>
            </div>
        </div>
    @endif

    @if (!empty($units))
        <div class="card">
            <h2>Tus bultos ({{ count($units) }})</h2>
            <ul class="units">
                @foreach ($units as $unit)
                    <li>
                        <span class="unit-label">{{ $unit['label'] }}</span>
                        <span class="unit-status">{{ $unit['status_message'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <footer>Dismac &middot; Este enlace es privado, no lo compartas.</footer>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    (function () {
        var tracking = @json($tracking);
        var pos = tracking.last_position;

        // ETA legible en hora local del navegador.
        if (tracking.eta) {
            var etaEl = document.getElementById('eta');
            if (etaEl) {
                try {
                    etaEl.textContent = new Date(tracking.eta).toLocaleString('es-BO', {
                        hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short',
                    });
                } catch (e) { etaEl.textContent = tracking.eta; }
            }
        }

        if (pos && typeof L !== 'undefined') {
            var map = L.map('map', { scrollWheelZoom: false }).setView([pos.lat, pos.lng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(map);
            L.marker([pos.lat, pos.lng]).addTo(map);
            L.circle([pos.lat, pos.lng], { radius: 180, color: '#E2231A', weight: 1, fillOpacity: 0.12 }).addTo(map);

            // "Reportado hace N min" junto al indicador en vivo.
            var metaEl = document.getElementById('position-meta');
            if (metaEl && pos.reported_at) {
                try {
                    var mins = Math.max(0, Math.round((Date.now() - new Date(pos.reported_at).getTime()) / 60000));
                    metaEl.textContent = metaEl.textContent + (mins === 0 ? ' · ahora' : ' · hace ' + mins + ' min');
                } catch (e) { /* se deja el texto base */ }
            }
        }
    })();
</script>
</body>
</html>
