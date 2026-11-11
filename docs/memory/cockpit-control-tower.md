---
name: cockpit-control-tower
description: "Rediseño del admin de CRUD a \"cockpit\" de operaciones (Torre de Control light/warm) — olas, decisiones, dónde está el código"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-05T09:20:18.634Z
---

El dueño quiere que el admin Filament deje de "verse como CRUD" y sea una sala de operaciones tipo TMS
moderno (mockups light/warm con riel oscuro, pulso de KPIs, ruta señalada, "operations brief" con causas
y acciones a aprobar, mapa en vivo, timeline de despacho). Referencia: 8 frames que compartió.

**Decisiones tomadas (del usuario):**
- Superficie = **Filament** (la SPA Vue/Inertia fue ELIMINADA; `vite.config.js` compila solo el tema; no hay Vue/charts/map en npm). Ver [[frontend-spa-vue-inertia]] (OBSOLETA: la SPA ya no existe).
- Look = **cockpit claro/cálido ("andén")** SCOPED a las páginas cockpit; el riel y las 61 pantallas CRUD conservan el tema Noche. El riel oscuro + tablero claro = los mockups.
- Inteligencia = **derivada REAL** (en riesgo = ventanas de `shipments.delivery_window` × estado/ETA de `execution_deliveries`); las acciones "Aprobar" despachan comandos REALES al CommandBus + asiento de auditoría. Nada de IA falsa, nada de datos inventados (sin reefer ni combustible vivo: no existen).

**Ola 1 — HECHA (landing Torre de Control):**
- Capa de diseño en `resources/css/filament/admin/theme.css` bajo `.distrack-cockpit` (tokens `--ck-*`, componentes `.ck-*`). Tipos ya auto-hospedados (Barlow/Condensed/IBM Plex Mono), CERO CDN.
- Componentes Blade anónimos en `resources/views/components/cockpit/` (surface, greeting, card, stat, pill, bar, agent-card, proposal, metric, route-timeline).
- Backend read-services en `app/Support/Cockpit/` (CockpitPulse, OperationsBrief, FlaggedRoute, OperationsActionService + Concerns/ReadsCockpitAggregates): tolerantes, cacheados ~20s, degradan a 0/[] si falta tabla. Approve → QueueNotification / ScheduleReplan / ExpireDueReservations + RecordAuditEntry.
- Página `app/Filament/Pages/ControlTower.php`: ahora extiende `Filament\Pages\Page` (antes Dashboard de widgets), ruta '/', vista propia `resources/views/filament/pages/control-tower.blade.php`. Refresca por `wire:poll.30s`; mapa Leaflet AUTO-HOSPEDADO (`/vendor/leaflet`, NO unpkg) con setInterval 30s vía `$wire.mapData()`.
- Caveats honestos: deltas de KPIs = null (no hay tabla de histórico); mapa es polling; timeline de despacho (Gantt por conductor) NO incluido aún (necesita read model nuevo).

**Verificado:** php -l + Pint OK en todo; bloques @php válidos; comandos de approve existen con firmas correctas. **NO renderizado** (sin Docker/navegador acá). PHPStan/Pest pendientes en CI/containers.

**TURNKEY `docker compose up` (sin pasos manuales):** orden de arranque encadenado por healthchecks:
DB(healthy) → **app** (entrypoint: `composer install` + `migrate` por `COMPOSER_INSTALL_ON_BOOT`/`AUTO_MIGRATE`
en env dev; healthcheck `nc -z 127.0.0.1 9000` en el ancla `x-php`, start_period 240s) → **assets** (depends_on
app:service_healthy → `npm run build`, one-shot) → **nginx** (depends_on app:healthy + assets:completed) y
**workers** (queue-worker/scheduler depends_on app:healthy → fin del crash-loop por vendor incompleto).
nginx usa `resolver 127.0.0.11` + `set $upstream_app app:9000; fastcgi_pass $upstream_app;` para
RE-RESOLVER app si se recrea (sin esto, `up -d` que recrea app pero no nginx da 502 por IP vieja). GOTCHA:
editar el default.conf bind-mounteado requiere `docker compose up -d --force-recreate nginx` (inode WSL).
Verificado: `up -d` levanta todo healthy, /admin 200, tema compilado, 0 errores. Tileserver sigue en perfil
`geo` (sin data mbtiles de Bolivia en `.docker/tileserver/data` → fallback radar oscuro; real tiles = cargar data + perfil geo).

**Build de assets automático en `up` (ya NO hace falta `npm run build` a mano):** se agregó el servicio
one-shot `assets` en `docker-compose.yml` (node:22-alpine) que corre `npm run build` en cada `docker
compose up`, montando el volumen `vendor` (el tema hace `@import vendor/filament`); `nginx` DEPENDE de que
termine (`service_completed_successfully`) para que el panel cargue estilado al primer request (sin
manifiesto Vite, Filament da 500). Si falta vendor (antes del 1er `composer install`), omite y sale 0; se
compila en el próximo up. `make install` agrega `$(DC) run --rm assets` tras composer. Para HMR de tema:
servicio `node` (perfil `node`, `make npm-dev`). La imagen PROD ya compilaba el tema en el stage `assets`
del Dockerfile (sin cambios). Ver [[ci-docker-vite-gotchas]].

**DIRECCIÓN VISUAL = OSCURA (NO claro/warm).** CORRECCIÓN CRÍTICA: el dueño usa el tema NOCHE y quiere el
cockpit OSCURO cohesivo. "quién hace blanco cuando el tema está en black" era LITERAL: lo quería dark.
Se intentó claro/warm (`ThemeMode::Light` + sección "ADMIN SHELL" warm) y JODIÓ todo el panel a blanco/negro
→ REVERTIDO. Estado final: `AdminPanelProvider ThemeMode::Dark` (shell Filament Noche NATIVO, no se
sobrescribe), y los tokens del cockpit en `theme.css` `.distrack-cockpit` son OSCUROS (canvas #141414, card
#1f1f26, texto claro, acentos brillantes: red #ff4d57, agent #9b8bff, green #3ad29a, amber #ffc24d). Superficie
transparente (hereda el #141414 del `.fi-main`). Formateo legible de ETA/ventanas (minutos→"16d 10h",
ISO→"19 oct. 09:00"). Mapa: "radar" OSCURO texturado + puntos de flota cuando no hay teselas; `.dark
.leaflet-container` oscuro para TODAS las páginas de mapa del admin. NO volver a forzar modo claro.

**"Mapa de flota en vivo" se quedaba cargando eterno:** cargaba Leaflet de **unpkg (CDN)** → lento/bloqueado.
Se pasaron los 4 blades de mapa del admin (live-fleet-map, geofence-map, route-map, shipment-delivery-map) a
Leaflet/Geoman AUTO-HOSPEDADO (`asset('vendor/leaflet/*')`), que ahora sí sirve tras arreglar el deny de nginx.
Pendiente: `public.blade.php` (tracking cliente) aún usa unpkg con integrity (migrar con cuidado por el SRI).

**BUG REAL arreglado (mapa en blanco):** `/.docker/nginx/default.conf` tenía `location ~ ^/(vendor|...)`
que DENEGABA `/vendor/leaflet/*` (Leaflet auto-hospedado en `public/vendor/`), por eso el mapa daba 404 y
los demás blades usaban unpkg (la "drift" CDN). Se agregó un `location ~ ^/vendor/(leaflet|leaflet-geoman)/`
ANTES del deny. El `vendor/` de composer está FUERA de `public/`, nunca fue alcanzable. GOTCHA WSL: editar
un archivo bind-mounteado como archivo único deja el inode viejo; `restart` falla → usar
`docker compose up -d --force-recreate nginx`. Teselas reales requieren el perfil `geo` (tileserver), si no
queda el radar cálido. Pendiente: quitar unpkg de los otros map blades (ya sirve el self-host). Ver [[admin-ui-maps]], [[distrack-rebrand-no-cdn]].

**PIPELINE DE SCREENSHOTS (clave — ya no se diseña a ciegas):** docker tiene acceso; se levanta playwright
`mcr.microsoft.com/playwright:v1.48.0-jammy` en la red `dismac_lg_network`, se instala `playwright-core@1.48.0`
(browsers en `/ms-playwright`), login `admin@dismac.test`/`password123`, screenshot de `http://nginx/admin`.
Script en el scratchpad (`shot.js`). Iterar: editar theme/blade → `docker compose run --rm --no-deps assets npm run build` → screenshot → Read PNG.

**Olas pendientes (acordar tras ver la ola 1):** 2) Live Tracking (elevar LiveFleetMap: lista de choferes + mapa + ficha de vehículo + nota de confianza; arreglar drift CDN unpkg en map blades). 3) Dispatch board + timeline Gantt (read model de carriles por conductor). 4) Modales de aprobación de ruta + compartir seguimiento.

Drift a limpiar (detectado): `packages/logistics/telemetry/.../live-fleet-map.blade.php` carga Leaflet de unpkg (viola CERO-CDN, [[distrack-rebrand-no-cdn]]); `vite.config.js` aún tira una fuente bunny.net. Ver [[admin-ui-maps]], [[admin-ops-first-redesign]], [[no-uuid-in-admin]] (el cockpit no muestra UUIDs).

---

**DisTrack OS — rediseño integral por fases (orden del dueño: "implementa todo, no pares, valida/audita cada fase al 100% antes de la siguiente; roles + admin acceden a todo").** Plan: **F1 Base** (kit de cockpit, modelo de acceso [admin=todo], command palette ⌘K, chart primitives) · **F2 Hubs 360** (Vehículo, Chofer, Envío, Nodo, Pedido/Cliente) · **F3 Workflows visibles** (pipelines por estado) · **F4 Homes por rol** · **F5 Datos que se ven** (charts) · **F6 Tiempo real** (Soketi) + racionalización de nav.

**Fase 1 — CERRADA Y VALIDADA (2026-11-05).** DoD verde: PHPStan nivel 8 = 0, Pint limpio, **pest 2140 passed / 5996 assertions**, + validación en navegador real.
- **Command palette ⌘K**: vive en render hooks `TOPBAR_END` (disparador `.dtk-cmd-trigger`) + `BODY_END` (montaje), gateado a OFICINA (`AdminPanelProvider::hasOfficeScope()` = admin/supervisor). **Arquitectura: Alpine + `fetch` a `/cockpit/search` (NO Livewire).** Livewire por-tecla daba **419** (CSRF/sesión en componente de render-hook); el endpoint JSON (`App\Http\Controllers\Cockpit\SearchController`, ruta `web`+`auth` en `routes/web.php`, alcance oficina o 403) lo resolvió y es más rápido. Vista `resources/views/filament/cockpit/command-palette.blade.php` (Alpine puro, debounce 220ms, descarta respuestas viejas por secuencia, índice plano `it._i` para teclado). Búsqueda transversal tolerante en `App\Support\Cockpit\GlobalSearch` (ilike sobre fleet_vehicles/fleet_drivers/shipments/network_nodes/carriers; devuelve referencia LEGIBLE, el UUID solo arma el href). CSP ya permite `connect-src 'self'`. Validado: `904`→"904U10 / En servicio"→Enter navega; admin ve disparador, chofer no.
- **Full-width** es global del panel (`->maxContentWidth(Width::Full)`); no sobreescribir por página (se quitó el override redundante de ControlTower y el trait muerto `IsCockpit`).
- **Estados terminales** viven en `App\Support\Cockpit\CockpitStates` (SHIPMENT_TERMINAL/DELIVERY_TERMINAL), NO en el trait `ReadsCockpitAggregates` (una const privada de trait se copia por clase y PHPStan la marca no-usada en consumidoras que no la referencian).
- Gotcha PHPStan: props de `DB::table()->...->first()` ⇒ tipar `\stdClass` (no `object`) para acceder a columnas en nivel 8.
**Fase 2 — Hubs 360 (EN CURSO).** Patrón por hub: (1) read-service `app/Support/Cockpit/<X>Hub.php` que compone datos cross-context vía QueryBus (CQRS) + lecturas directas tolerantes para huecos, devolviendo primitivos; (2) vista propia `resources/views/filament/fleet/<x>-hub.blade.php` con `<x-filament-panels::page>` como raíz (conserva cabecera+acciones) y la capa `.distrack-cockpit` (hero + KPIs `.ck-stat` + `.ck-tabs` Alpine `x-data="{t:'...'}"` + `.ck-card/.ck-dl/.ck-events/<x-cockpit.chart>`); (3) la página `View<X>` pone `protected string $view=...`, `#[Computed] hub()` (memoiza, usa `$this->getRecord()->getKey()` NO `$this->record->getKey()` → PHPStan), y `photoUrl()`. CSS nuevo del hub (hero foto, `.ck-photos`, `.ck-events`, regla `[x-cloak]`) al final de theme.css.
- **Hub de Vehículo HECHO Y VALIDADO** (`VehicleHub` + `vehicle-hub.blade.php` + `ViewVehicle`): hero (placa/estado/telemetría en vivo), KPIs (costo-km, km telemetría, odómetro, documentos), pestañas Resumen (ruta de hoy `.ck-timeline` + costo desglosado `.ck-bar`), Costos (chart km/día + totales), Ficha técnica (dl + 4 fotos), Documentos, Actividad (alertas+incidentes). Queries: GetLiveFleetPositions(filtrar por vehículo), GetDriverRoute, GetVehicleCostSummary, GetVehicleUtilization, ListExpiringDocuments, ListDocuments, ListAlerts(limit→**devuelve Page, usar ->items**); huecos por lectura directa: chofer de hoy (fleetops_departures), incidentes (execution_vehicle_incidents), serie km (telemetry_trips).
- **Hub de Chofer HECHO Y VALIDADO** (`DriverHub` + `driver-hub.blade.php` + `ViewDriver`): hero (nombre/licencia/estado/tripulación hoy), KPIs (entregas, efectividad %, días, documentos), pestañas Resumen (desempeño GetDriverHistory + tripulación GetMyCrew), Personal (datos+referencias), Licencia y documentos, Actividad (entregas/día + alertas telemetry_alerts por driver_id directo). GetDriverHistory(driverId,from,to)→DriverHistoryView; GetMyCrew(driverId,date)→MyCrewView (vehicle{plate}, helpers[{name}]).
- **Hub de Envío HECHO Y VALIDADO** (`ShipmentHub` + `shipment-hub.blade.php` + `ViewShipment`): hero (nº pedido/cliente/estado/ETA), KPIs (estado, ETA, ventana, confianza dirección), pestañas Resumen (dirección+entrega/seguimiento), Productos (líneas+bultos), Entrega (POD foto+confirmación+vehículo). FindShipment→ShipmentView, GetShipmentTracking→ShipmentTrackingView, GetShipmentUnits→UnitTrackingView; entrega por lectura directa execution_deliveries (vehicle_id→placa, pod_*).
- **Hub de Nodo HECHO Y VALIDADO** (`NodeHub` + `node-hub.blade.php` + `ViewNode`): hero (código/tipo/depto/estado/coords), KPIs (vehículos en base, envíos destinados, muelles, conexiones), pestañas Resumen (mapa Leaflet self-host con `@assets`/`@script` + `DismacMaps.tileLayer`), Flota (fleet_vehicles.home_base_node_id), Conexiones (ListLegs filtrando from/to==nodo, resolviendo el otro extremo a nombre), Muelles (ListReceivingDocks). FindNodeQuery→NodeView.
- **Hub de Pedido/Cliente HECHO Y VALIDADO** (`OrderHub` + `order-hub.blade.php` + `ViewOrder` NUEVO): hero (cliente/estado/total/pago), KPIs (estado, total, ítems, pago), pestañas Resumen (cliente/entrega + pago/factura), Productos (líneas con nombre+SKU+precio+total), Pago y factura. GetOrder→OrderView, GetPaymentByOrder→PaymentView, GetInvoiceByOrder→InvoiceView. OJO: OrderResource NO tenía página View → se agregó `'view'=>ViewOrder::route('/{record}')` en getPages + `ViewAction` en recordActions + se extrajo `cancelAction()` estático (reusado en tabla y cabecera del hub). Se sembró 1 pedido demo (María Fernández, Bs 795) porque commerce_orders estaba vacío.

**FASE 2 (Hubs 360) COMPLETA Y VALIDADA (2026-11-05):** los 5 hubs (Vehículo/Chofer/Envío/Nodo/Pedido) en dark cockpit, datos reales cross-context, PHPStan 8 = 0, Pint limpio, pest 2140 passed, validados en navegador.

**FASE 3 (Workflows visibles) — pipeline de última milla HECHO Y VALIDADO:** página `App\Filament\Pages\ShipmentPipeline` (auto-descubierta, grupo "Operations", office-gated, ruta `/admin/shipment-pipeline`) + servicio `App\Support\Cockpit\ShipmentPipeline` (alias `PipelineData` en la página por colisión de nombre) + vista `filament/pages/shipment-pipeline.blade.php`. Tablero kanban con el kit `.ck-pipeline/.ck-col/.ck-ticket` (tonos --late/--warn/--done/--agent): columnas por estado (RECEIVED→PLANNED→IN_EXECUTION→DELIVERED/FAILED), conteo por columna, tarjetas con ref+cliente+ventana+placa clickeables al hub del envío, borde rojo para atrasados (ventana vencida), "+N más", `wire:poll.60s`. Validado: 5 cols, 140 recibidos/155 planificados, 3 atrasados. **Order pipeline (OMS pending→paid→fulfilling→cancelled) queda como extensión trivial del mismo patrón (no hecho: commerce_orders casi vacío).**

**GOTCHA: una Filament Page/Resource NUEVA da 404 hasta `php artisan optimize:clear`** (rutas + caché de Filament estaban cacheadas en el entorno dev). Tras `optimize:clear`, `route:list | grep <slug>` la muestra. El slug de página se deriva del nombre de clase (ShipmentPipeline → `shipment-pipeline`). Páginas se auto-descubren con `->discoverPages(in: app_path('Filament/Pages'))` en AdminPanelProvider.

**FASE 4 (Homes por rol) HECHA Y VALIDADA:** el landing ControlTower (`/`) ya ramificaba por `hasOpsScope()`; se reemplazó el mensaje genérico de campo por homes reales. `ControlTower::roleHome()` elige por `users.driver_id` (chofer) o `users.node_id` (tienda); null → genérico (preparador/transportista). Servicios `App\Support\Cockpit\DriverHome` ("mi día": GetMyCrew→vehículo+ayudantes, GetDriverRoute→ruta/progreso/próxima parada, timeline de paradas) y `StoreHome` ("mi tienda": envíos destinados al nodo por estado + recientes). Partials `resources/views/filament/cockpit/home-driver.blade.php` y `home-store.blade.php` (dentro de la superficie cockpit de ControlTower, kit `.ck-*`). Se gatearon las acciones de oficina del greeting (Monitoreando/aprobaciones/Nuevo envío) tras `@if($this->hasOpsScope())` (un chofer/tienda no las ve). Validado: tienda en navegador (greeting sin controles de oficina, KPIs por estado, recientes, nav reducida); chofer **server-side** (/admin/control-tower → 200 + home renderizado) — su cuenta de prueba `chofer@dismac.test` tiene **2FA** (secreto en col `app_authentication_secret`, cifrado), por eso no se pudo loguear en el navegador headless (y NO se debe descifrar/generar el TOTP — el guardrail lo bloquea, correctamente).

**FASE 5 (charts — "datos que se ven") HECHA Y VALIDADA:** servicio `App\Support\Cockpit\CockpitTrends` (usa el trait `ReadsCockpitAggregates` para cachear) con `receivedPerDay()`/`deliveredPerDay()` (series diarias 14d derivadas de shipments.created_at y execution_deliveries DELIVERED, rellenadas con 0) + `statusDistribution()` (conteo por estado con tono) + `totals()`. `ControlTower::trends()` lo expone; sección "Tendencias" en el branch de OFICINA de control-tower.blade: "Carga por estado" (barras `.ck-bar` 140/155…) + "Últimos 14 días" (`<x-cockpit.chart type="area">` recibidos + `type="bars"` entregados). Validado en navegador. Los hubs (vehículo km/día, chofer entregas/día) y el pipeline ya aportaban gráficos. Nota: el seed concentra creados en 1 día → la serie de recibidos es spiky (240 pico), honesto.

**NAV por rol (parte de F6, hecho por pedido del usuario):** "Tienda no tiene porqué ver mapa de cobertura o nodos" → se agregó `use OfficeOnly;` (trait `Logistics\Shared\...\Concerns\OfficeOnly`, da `canAccess()`=admin/supervisor) a `App\Filament\Pages\CoverageMap` y `NodeMap`. Auditoría de nav por rol de campo (canAccess de todos los resources/pages del panel): Chofer ve Delivery/ScanEvent/VehicleRoute/ShipmentTracking/ControlTower/LastMileBoard; Preparador ScanEvent+ControlTower; Transportista ShipmentTracking+ControlTower; Tienda NodeReception+ShipmentTracking+ControlTower — todo apropiado. GOTCHA: cambio de `canAccess` requiere `optimize:clear` para reflejarse en la nav.

**FASE 6 (tiempo real — incremento enfocado) HECHA Y VALIDADA:** la infra ya estaba (`.env` BROADCAST_CONNECTION=pusher + PUSHER_*/VITE_PUSHER_* → Soketi:6001; evento `Logistics\Telemetry\...\Broadcasting\VehiclePositionUpdated` ya se despacha en la ingesta, canal público `fleet`, evento `.vehicle.position`). Faltaba SOLO el cliente: se agregó `laravel-echo`+`pusher-js` a package.json, `resources/js/echo.js` (init env-gated por VITE_PUSHER_APP_KEY), se metió al input de vite.config.js, se carga por render hook HEAD_END (`filament/realtime-echo.blade.php`, solo oficina), y el script del mapa de ControlTower se suscribe a `Echo.channel('fleet').listen('.vehicle.position', …)` → refresca el mapa al instante (debounce 1.5s) con el polling 30s como fallback. CSP: `SecurityHeaders::broadcastConnectOrigins()` agrega `ws://localhost:6001`+`http://…` a connect-src SOLO si broadcasting.default=pusher (config nueva `broadcasting.client_ws` ← VITE_PUSHER_*). Validado en navegador: Echo loaded, pusher activo, wsHost localhost:6001, key ok, CERO violaciones CSP, cero errores JS. (El push end-to-end real necesita navegador en el host: localhost:6001→Soketi; el headless corre dentro de la red docker y no alcanza ese localhost, pero el cableado+CSP quedó validado.)

**REDISEÑO DISTRACK OS: F1–F6 COMPLETO Y VALIDADO (2026-11-05)** — pest 2140 passed en cada cierre, PHPStan 8 = 0, Pint limpio.

**PIVOTE (decisión del dueño, confirmada EN esta sesión 2026-11-05):** el admin queda EN PAUSA; nueva prioridad = **etiquetado por orden + multishipping**. Orden: (A) enriquecer `EmittedLabelView` (labels) con product_name/image_url/origin/destination (nombres humanos, cero uuid) → (B) solo listar líneas AUTORIZADas (plan publicado) + preview (confirmar A PNG renderizado vs B qr_content) → (C) **modelo multishipping** n-a-n producto→destino + `GET /app/orders/{orderNumber}/dispatch-lines` (destino por línea). Coordino con la sesión app `logistics-app-8e` (ella regenera el cliente Dart desde openapi/v1.yaml cuando entregue A; ya tiene el preview B andando). Ver doc del dueño: `docs/backend-requests/2026-11-05-etiquetado-por-orden-multishipping-preview.md`. Contexto labels: `packages/logistics/labels` (EmittedLabelView, GetShipmentLabels, LabelController).

**ETIQUETADO (A) — EmittedLabelView ENRIQUECIDO: HECHO Y VALIDADO (2026-11-05).** Patrón: enriquecimiento cross-context en LECTURA, sin acoplar el dominio (como [[api-readmodel-enrichment]]). 
- `EmittedLabelView` ahora expone: `product_name`, `image_url` (URL servible absoluta), `origin{code,name}`, `destination{type: home|store|warehouse, name, address}` — nombres humanos, cero uuid. `fromEmittedLabel` arma la base (origin.code del QR); `enriched()` devuelve copia con los nombres resueltos.
- Contrato `Logistics\Labels\Application\Contract\LabelEnricher` + impl `Infrastructure\ReadModel\EloquentLabelEnricher` (bind en LabelsServiceProvider), inyectado en `GetShipmentLabelsHandler`. Resuelve: producto por SKU (shipment_lines del envío de la orden → fallback commerce_products.canonical_sku: name + primera media por position, URL vía Storage::disk('public')->url si es path relativo); destino del envío (shipments.external_id=orderNumber → destination_node_id? nodo: network_nodes.type→{store: store/pickup_point; warehouse: distribution_center/central_warehouse} + name; si null → home con customer_name + address_text(+reference)); origen = `org` del QR (accessor nuevo `EmittedLabel::originCode()`) resuelto a network_nodes.name por code, fallback code. TODO tolerante (try/catch por fuente → null). 
- DATOS REALES: NO hay origen persistido por orden (solo el código `org` del QR, free-form); shipments NO tiene origin_node_id; orden↔envío es 1:1 por (source, external_id) → destino ÚNICO por orden (el n-a-n producto→destino es la parte C multishipping). Preview: solo qr_content/zpl, NO hay PNG (la app usa opción B; A=PNG es hueco futuro). Autorización (parte B) = envío con stop en planning_plan PUBLISHED (planning_plan_stops.shipment_id).
- OpenAPI: schema `EmittedLabel` actualizado en `openapi/paths/labels.yaml` Y en el bundle `openapi/v1.yaml` (NO hay bundle automático → editar ambos a mano). Tests: `packages/logistics/labels/tests/Feature/LabelEnrichmentTest.php` (home/nodo/tolerante) + EmittedLabelViewTest actualizado. La sesión app `logistics-app-8e` regenera el cliente Dart desde v1.yaml.
**ETIQUETADO (C) dispatch-lines + (B) autorización — HECHO Y VALIDADO (2026-11-05).** Endpoint `GET /app/orders/{orderNumber}/dispatch-lines` (ruta en routes/api/labels.php, puerta app). Envelope: `{ order_reference, destinations:[{shipment_reference, destination, line_count}], data:[DispatchLine] }`. DispatchLine (contrato aprobado por la app): sku, product_name, image_url (URL servible), quantity, pieces_per_unit (default 1), line_number (1-based), origin{code,name}, destination{type: home|store|warehouse, name, address}, shipment_id (opaco, agrupar), shipment_reference (legible), authorized(bool), authorized_reason, label_status(pending|emitted|revoked). 
- Query `GetDispatchLines` + handler + contrato `DispatchLinesReader` + impl `EloquentDispatchLinesReader` (bind + registro de handler en LabelsServiceProvider) + método `LabelController::dispatchLines`. 
- Forma n-a-n (destino POR LÍNEA) desde ya; hoy 1 orden=1 envío → destino único. Líneas = shipment_lines (ordenadas por id → line_number). (B) AUTORIZACIÓN = envío con parada en `planning_plans` status='PUBLISHED' (join planning_plan_stops) → authorized; label_status desde EmittedLabelRepository->forOrder (sku|lineNumber → emitted/revoked/pending); origen del `org` de la etiqueta emitida (null en pending). Tolerante por fuente.
- OpenAPI: path + schemas DispatchLinesResponse/DispatchLine/DispatchDestination en `openapi/paths/labels.yaml` Y `openapi/v1.yaml`. Tests: `DispatchLinesTest.php` (home/pending/no-autorizado, autorizado+nodo, vacío). 
- **ETIQUETADO COMPLETO (A+B+C+3):** (3) preview=B (sin cambio backend; PNG futuro). La sesión app regenera el cliente Dart UNA vez (A+C juntos) cuando tenga Dart 3.13 (su WSL tiene 3.8); mientras usa preview B. 
- **PRÓXIMO: retomar el ADMIN DisTrack OS al 100% desde donde quedó (F1–F6 ya completos; pulido/lo que el dueño indique).**

**GOTCHA Carbon 3 (Laravel 13): `diffInDays`/`diffInMinutes` ahora vienen CON SIGNO por defecto** (negativo si el argumento es anterior). `$exp->diffInDays($today)` con exp futuro da NEGATIVO → rompió el estado de licencia ("por vencer" cuando faltaban años). Para días absolutos usar `DateTimeImmutable::diff()->format('%a')` (siempre positivo) y detectar vencido con `$exp < $today` aparte — como hacen `expiryState()` en los hubs. FlaggedRoute pasa `false` explícito y envuelve en `abs()`.

**GOTCHA Blade + Livewire: un `@if(...)...@endif` INLINE y ANIDADO, pegado a `}}` en la misma línea, rompe la inyección de guardas `[if BLOCK]` de Livewire** → ParseError "expecting endif" al compilar (aunque las directivas estén balanceadas). Pasó en vehicle-hub (velocidad en vivo). Solución: usar un ternario dentro del `{{ }}` en vez del `@if` inline anidado. Diagnóstico: `Blade::compileString()` + `php -l` sobre el compilado, y bisección por `sed` DENTRO del container (el /tmp del host no se ve). Imagen playwright que matchea: `mcr.microsoft.com/playwright:v1.48.0-jammy` (playwright-core 1.48), red `dismac_lg_network`, base `http://nginx`.
