---
name: telemetry-context
description: "El bounded context telemetry: GPS en vivo de camiones, trips, analítica de calles, geocercas y mal uso"
metadata:
  node_type: memory
  type: project
  originSessionId: 95be9a11-8872-4d3d-b8b2-ef0548264b66
  modified: 2026-10-13T23:32:03.244Z
---

Contexto `packages/logistics/telemetry` (namespace `Logistics\Telemetry\`), construido el 2026-10-14 con
agentes en paralelo (3 núcleo por grupo + 3 presentación), DDD+CQRS+Clean, UUID v7, outbox. Llena el
hueco que faltaba: **ingesta de stream GPS continuo de los camiones** (antes solo existían `TruckPosition`
en tracking y scans IN_TRANSIT en execution, sin endpoint de ingesta real). El repo **NO usa PostGIS**:
coordenadas `decimal(10,7)`; geocercas = polígono JSON + ray-casting en PHP; distancias = haversine.

**Qué da (lo que pidió el dueño):**
- **Ubicación en vivo** de todos los camiones (mapa de flota) + histórico (track) para replay.
- **Trips**: por recorrido, distancia, duración, tiempo en movimiento vs **inactivo**, velocidad máx → "cuántos minutos para entregar X".
- **Analítica de calles** (transitadas vs libres): map-matching de los pings con OSRM `/match` → `telemetry_street_stats` con velocidad media/congestión por segmento. Worker `telemetry:aggregate-streets` (scheduler diario 01:15).
- **Control / mal uso**: geocercas (CD, tienda, zona autorizada/**prohibida**, área de descanso) + `MisuseAnalyzer` (puro) con 5 reglas: exceso de velocidad, **movimiento fuera de horario**, entrar a zona prohibida, **inactividad excesiva** (descanso), **uso sin ruta asignada** → alertas al supervisor (outbox 'telemetry.alert').

**Agregados/piezas:** entidad `VehiclePing` (NO AggregateRoot, NO evento por ping — alto volumen), agregados `Trip`,
`Geofence`, `TelemetryAlert`, `StreetSegmentStats`; servicios de dominio `TripTracker` y `MisuseAnalyzer`;
puerto `MapMatcher` → `OsrmMapMatcher` (OSRM `/match`, reusa `OSRM_HOST`). Config `config/logistics-telemetry.php`
(umbrales de movimiento/inactividad/horario/velocidad). Tablas: `telemetry_pings` (append-only, idx (vehicle_id,recorded_at)),
`telemetry_vehicle_positions` (PK vehicle_id, posición viva del mapa), `telemetry_trips`, `telemetry_geofences`,
`telemetry_alerts`, `telemetry_street_stats`.

**Ingesta:** command `RecordVehiclePings` (lote; app agrupa lecturas). El handler cablea (orquestador):
appendMany pings → TripTracker mantiene el trip → upsert posición viva → `MisuseAnalyzer` sobre la última
lectura levanta alertas con **debounce** (una alerta abierta por tipo/vehículo). Outbox solo en TripStarted/TripCompleted/AlertRaised.

**API:** puerta `app` `POST /api/v1/app/telemetry/pings` (chofer, auth:sanctum+device) + lecturas (fleet/live,
vehicles/{id}/track, trips, alerts, summary, streets) + gestión de geocercas; puerta `integrations`
`POST /api/v1/integrations/telemetry/pings` (GPS de proveedores externos, service.auth). OpenAPI aditivo (15 paths).

**Admin Filament** (grupo `Telemetry`): página `LiveFleetMap` (Leaflet/OSM, camiones en vivo, polling 10s, click→track
con OSRM /match), `GeofenceResource` (dibuja polígono con Leaflet-Geoman → DefineGeofence/RedrawGeofence),
`TripResource` (reportes), `TelemetryAlertResource` (alertas + Acknowledge). Blades con `loadViewsFrom(..., 'logistics-telemetry')`.

Registrado en los lugares de siempre: `composer.json` raíz (require+dont-discover), `bootstrap/providers.php`
(tras Tracking), `AdminPanelProvider` (discoverResources + NavigationGroup Telemetry + Page LiveFleetMap en `->pages([])`),
`routes/api.php` (require fragmento), `DatabaseSeeder` (TelemetryDemoSeeder idempotente: 3 geocercas + recorrido
de 20 pings/trip + posición viva para vehículos existentes de fleet).

**DoD VERDE (2026-10-14):** PHPStan 8 limpio (módulo y repo global 0), Pint limpio (1917 files), Pest del módulo
**49 passed/112 aserciones** (Unit+Feature), Filament resuelve, verificación en vivo end-to-end (ingesta→posición→trip→
alertas speeding+geofence_violation, outbox). Ver [[project-architecture]], [[routing-geo-rule]], [[admin-ui-maps]], [[no-destructive-db-ops]].
