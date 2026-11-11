---
name: admin-crm-phases
description: "Rediseño admin CRUD→CRM por fases F1-F5 COMPLETAS (nav, segmentos/360/cross-link, timeline/vistas/bulk, Cliente360+notas/homes/analítica/colas, notas-en-todo+Agenda/tiempo-real/reportes/⌘K) — sin commitear"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-06T21:10:08.110Z
---

Rediseño del admin Filament de "CRUD" a "CRM", por fases, sobre el cockpit DisTrack OS ya existente
([[cockpit-control-tower]], [[admin-ops-first-redesign]]). **Nada de esto está commiteado — el dueño
commitea/pushea él mismo.**

**Fase 1 — COMPLETA (nav).** 6 grupos (Comercio → Clientes y experiencia [nuevo] → Operaciones → Flota →
Red → Configuración), orden por `NavigationGroup::make(__('...'))` en AdminPanelProvider (la etiqueta DEBE
coincidir con la traducida que devuelven los recursos, si no Filament pierde el orden). Casing arreglado
global con trait `Logistics\Shared\...\Concerns\PlainNavigationLabel` (devuelve getPluralModelLabel sin el
Title Case que mete Filament). Duplicados/spanglish resueltos (Catálogo de productos, Categorías, Mapa de
nodos, Asignaciones de tienda). "Pedidos recibidos"→"Envíos" movido a Operaciones. ShipmentLineResource
fuera de la nav (shouldRegisterNavigation=false). NO anidar con getNavigationParentItem: oculta ítems sin
chevron (se probó y revirtió).

**Fase 2 — COMPLETA (CRM core).** Segmentos por estado (getTabs con badges de conteo) en 8 listados:
Pedidos/Envíos/Entregas/Promesas/Vehículos/Choferes/Seguimiento/ÓrdenesMant. Gotcha PHPStan: el closure de
`modifyQueryUsing` recibe `Builder<Model>` genérico → usar closure de bloque con `/** @var Builder<Record> */`.
Ficha 360 como entrada: `recordUrl(fn($r)=>self::getUrl('view',['record'=>$r]))` en los 5 resources con hub
(Vehicle/Driver/Order/Shipment/Node) → click en fila abre el hub. Cross-linking parcial: Envío→Vehículo,
Vehículo→Chofer (expuse driver_id/vehicle_id en VehicleHub/ShipmentHub + enlaces `ck-link` en los blades).

**Fase 3 — EN CURSO (el dueño pidió los 4 al 100%): cross-linking total, vistas guardadas por usuario,
acciones masivas/workflow en listas, líneas de tiempo por entidad.** Grupo A (hubs) HECHO; Grupo B (listados)
en un fork paralelo (particionado por archivos: hubs vs páginas ListX; es.json solo lo toca el fork, theme.css
solo yo). Estado:
- **Líneas de tiempo — HECHO y validado.** read-service `App\Support\Cockpit\EntityTimeline` (fusiona
  outbox_messages por aggregate_id + access_audit_log por subject_id, mapa curado evento→español + fallback,
  desc, limit 30). Componente `resources/views/components/cockpit/timeline.blade.php`. `#[Computed] timeline()`
  en los 5 ViewX (ViewShipment pasa [id, external_id]). Sección `<x-cockpit.timeline :events="$this->timeline" />`
  agregada en una pestaña "Actividad" en los 5 hubs (vehicle ya, + shipment/order/driver/node). Validado en
  navegador: shipment-hub muestra evento real "Etiquetas emitidas · 23 oct"; los demás muestran el vacío
  elegante cuando no hay eventos (datos sembrados directo a DB no generan eventos de outbox).
- **Cross-linking total — HECHO.** Chofer→Vehículo (DriverHub.crewToday expone `vehicle_id`; enlace en el
  pill "Hoy" + título de tripulación). Nodo→Vehículos (NodeHub.fleet expone `id`; placa enlaza a VehicleResource;
  validado links=1). Nodo→Envíos destinados (NodeHub.shipments ahora trae `recent` [id,ref,customer,status] →
  pestaña "Envíos" con enlaces a ShipmentResource). Pedido→Envíos (OrderHub.shipments() por
  `shipments.order_reference = orderId`, ADR 018 → pestaña "Envíos" con enlaces; vacío en órdenes sembradas
  porque sus envíos no nacen del flujo order_paid). Envío→Vehículo ya venía de Fase 2. Patrón de enlace:
  `ResourceClass::getUrl('view', ['record'=>$id])` + `wire:navigate class="ck-link"` (el IDE marca getUrl como
  método desconocido = falso positivo de Filament; PHPStan 8 pasa limpio).
- **Vistas guardadas por usuario — en el fork (Grupo B).** Plan: tabla `cockpit_saved_views` + trait reusable
  en ListRecords (acción "Guardar vista" + dropdown "Mis vistas").
- **Vistas guardadas por usuario — HECHO (Grupo B).** Tabla `cockpit_saved_views` (user_id, resource, name,
  payload json {tab, filters}, índice user_id+resource; migración aditiva/idempotente). Modelo
  `App\Models\CockpitSavedView`. Trait `App\Filament\Concerns\HasSavedViews` (acción cabecera "Save view" captura
  `activeTab`+`tableFilters`; grupo "My views" aplica/borra; se oculta sin vistas) montado vía
  `getSavedViewActions()` en getHeaderActions de las 8 páginas ListX.
- **Acciones masivas/workflow — HECHO (Grupo B).** Exporter universal solo-lectura `App\Filament\Support\TableCsvExport`
  (CSV de columnas visibles, nunca UUID) en las 8 listas; bulk de negocio vía CommandBus (CQRS, no escribe el
  Record): Pedidos→Cancelar en lote (`CancelOrder`), Vehículos→Cambiar estado en lote (`ChangeVehicleStatus`).
  es.json +17 claves (el fork es dueño único de es.json; re-ordenó determinísticamente por SORT_STRING, 0 claves
  perdidas). Gotcha: `formatState()` no existe en el `Column` base → guard con `method_exists`.

**DoD final de la Fase 3 (2026-11-06): pest 2190 passed / 6178 assertions (exit 0) · PHPStan nivel 8 = 0 (todo el
repo) · Pint PASS (3689 archivos) · validado en navegador (hubs con timeline/cross-links, listas con "Guardar
vista" + bulk + descarga CSV real).** TODO SIN COMMITEAR — el dueño commitea/pushea él. Nit pre-existente fuera de
alcance: columna "Placed at" en OrderResource sigue en inglés (no es de F3).

**Fase 4 — COMPLETA (4 frentes).** Páginas cockpit nuevas se AUTO-DESCUBREN desde `app/Filament/Pages` (NO se
toca AdminPanelProvider). Particionado: 2 forks (Analítica, Necesita atención) + yo (Cliente 360 + notas + homes
por rol). es.json NO se tocó (páginas cockpit = excepción español-directo; grupo de nav con `__('Operations')`/
`__('Commerce')`, claves existentes). theme.css NO se tocó (clases ck-* ya existían). Entregado:
- **Cliente 360 + notas/actividad.** `App\Support\Cockpit\CustomerHub` agrega por NOMBRE de cliente: envíos,
  pedidos OMS (`ship_to->>'customer_name'`), entregas, satisfacción (`tracking_satisfaction_surveys`), gasto,
  efectividad, última actividad. `app/Filament/Pages/CustomerProfile.php` reescrita a hub cockpit (hero+KPIs+tabs
  Resumen/Pedidos/Envíos/Satisfacción/Direcciones/Notas + mapa Leaflet self-host). Notas genéricas reutilizables:
  tabla `cockpit_notes` (polimórfica subject_type/subject_id, kind note|task, due_on, done_at), modelo
  `App\Models\CockpitNote`, add/toggle-tarea/borrar (Livewire). Directorio buscable `CustomersDirectory`
  (/admin/customers-directory, grupo Comercio, "Clientes") → entra al 360. (Validado: nota creada end-to-end,
  búsqueda filtra.)
- **Homes por rol.** driver/store ya existían en `ControlTower::roleHome()`; AGREGUÉ picker (preparador →
  `PickerHome`, cola de preparación) y carrier (transportista → `CarrierHome`, liquidaciones) + partials
  `home-picker`/`home-carrier`. admin/supervisor siguen con el cockpit ops completo. Los 6 roles entran al panel
  (canAccessPanel). `user_roles.role` es STRING (no role_id); test accounts `<rol>@dismac.test`/password123.
- **Analítica (fork).** `/admin/analytics` (Operaciones): `Analytics`+`AnalyticsBoard`, usa CockpitTrends/
  CockpitPulse + `<x-cockpit.chart>` (efectividad, carga por estado, tendencias 14d, concentración por nodo).
- **Necesita atención (fork).** `/admin/needs-attention`: `NeedsAttention`+`AttentionQueues` (4 colas de triage:
  envíos atascados, entregas fallidas sin reprogramar, pedidos pagados sin envío, docs vencidos) con enlaces al
  recurso; sin UUID.
Gotcha: `use`/`@use` dentro de un @php de Blade puede NO compilar → FQN inline en closures (patrón hub blades).
`getNavigationGroup()` que nunca retorna null va `: string` (no `: ?string`) por PHPStan.

**Fase 5 — COMPLETA (4 frentes).** Particionado: 2 forks (Reportes, ⌘K) + yo (Notas en todo+Agenda, Tiempo real).
- **Notas/tareas en todas las fichas + Agenda.** Extraje el muro de notas a componente `<x-cockpit.notes :notes>`
  y un trait reutilizable `App\Filament\Concerns\HasEntityNotes` (ViewRecord: props noteBody/noteKind/noteDue +
  addNote/toggleTask/deleteNote + `#[Computed] notes()`; `abstract noteSubjectType()`). Aplicado a los 5 hubs
  (ViewVehicle/ViewShipment/ViewOrder/ViewDriver/ViewNode) con pestaña "Notas". `App\Filament\Pages\Agenda`
  (/admin/agenda, Operaciones) junta TODAS las tareas abiertas de cualquier entidad, vencidas→hoy→próximas→sin
  fecha, resuelve etiqueta+enlace por tipo (sin UUID), marca hecha. Validado: tarea creada en hub de vehículo
  aparece en Agenda.
- **Tiempo real (WebSockets).** YA estaba el grueso: soketi UP (docker-compose), `BROADCAST_CONNECTION=pusher`,
  `VehiclePositionUpdated` (ShouldBroadcast), `echo.js` (en el manifest de vite), channels.php registrado en
  bootstrap, y el control-tower.blade suscribe `fleet`/`.vehicle.position`. AGREGUÉ: indicador "EN VIVO" (estado
  real de la conexión pusher) y refresco en vivo de KPIs/brief (`$wire.$refresh()` debounced; el mapa va en
  `wire:ignore`, no se reinicia). VERIFICADO end-to-end: trigger app→soketi `ok=true`; cliente pusher-js en la
  red docker `connected=true eventReceived=true`. NOTA: extender el push a eventos de envío/tracking NO es
  seguro por config: el `FanOutMessageDispatcher` del outbox busca por NOMBRE EXACTO de evento, así que rutear un
  prefijo (`shipments.`/`execution.`/`tracking.`) a fanout DESCARTARÍA las notificaciones. Queda como follow-up
  deliberado. (En el test Playwright el indicador sale "Sin conexión" porque el cliente usa `localhost:6001` y en
  el contenedor localhost≠soketi; en el navegador real del host sí conecta.)
- **Reportes y exportación (fork).** `/admin/reports`: `Reports`+`ReportBuilder`, 5 CSV (StreamedResponse, BOM
  UTF-8) + comando `ops:digest` (schedule dailyAt 07:30 en routes/console.php) que manda resumen por mail a
  admin/supervisor. Gotcha: el mailer del entorno apunta a mailpit (caído); el comando degrada con gracia; en
  prod sale por el mailer real.
- **Búsqueda global ⌘K (fork + yo).** La paleta YA EXISTÍA: overlay Alpine `filament.cockpit.command-palette`
  (fetch a `/cockpit/search` → `SearchController` → `GlobalSearch`), montada por render hooks palette-mount/
  search-trigger en AdminPanelProvider. El fork EXTENDIÓ `GlobalSearch` (+Clientes +Pedidos) y creó un
  `app/Livewire/CommandPalette` DUPLICADO que BORRÉ (redundante). Yo arreglé `SearchController` para resolver las
  URLs de los 2 tipos nuevos (`order`→OrderResource, `customer`→CustomerProfile::urlFor). Validado: ⌘K abre,
  agrupa ENVÍOS+CLIENTES+…, enlaces reales. REGLA: antes de construir, revisar si la feature ya existe
  (palette, analítica, homes por rol ya estaban parcial/totalmente hechos).

**Reestructuración del menú CRUD→CRM (post-F5, pedido del dueño: "parece un CRUD, muchas opciones sueltas").**
Dos cambios:
1. **Grupos colapsados por defecto** — `NavigationGroup::make(__('...'))->collapsed()` en AdminPanelProvider para
   los 6 grupos. El menú abre compacto (Torre de control + 6 encabezados); cada grupo se expande al entrar y
   Filament recuerda el estado. Sin ocultar nada.
2. **Recursos de detalle DENTRO de su entidad (no ítems sueltos del menú)** — regla del dueño: "lo que depende
   de X vive dentro de X" (odómetro/mantenimiento dentro del vehículo, etc.). Patrón: `shouldRegisterNavigation()
   => false` en el recurso + una sección **«Gestión de <entidad>»** en la ficha 360 del padre (pestaña Resumen)
   con pills-enlace `managementLinks()` que abren la LISTA del recurso ya FILTRADA al padre por su
   `SelectFilter('vehicle_id')` → `?tableFilters[vehicle_id][value]=<id>` (donde existe; si no, enlace al index).
   Demotados a su entidad (12): **vehículo** → Odómetro, Combustible(Refuel), Mantenimiento(Order+Plan),
   Salidas(Departure), Gastos(Expense), Recorridos(Trip), Checklists(PreTrip), Ficha técnica(VehicleProfile),
   Tripulaciones(Crew); **chofer** → Tripulaciones, Documentos; **nodo** → Tramos(Leg); **pedido/envío** →
   Etiquetas(EmittedLabel). `managementLinks(): list<array{label,url}>` en ViewVehicle/ViewDriver/ViewNode/
   ViewOrder/ViewShipment (tolerante, class-string<Resource>, rawurlencode del id). NO se demotó lo que es
   master/compliance/cross-entidad (Vehículos, Choferes, Ayudantes, Precios combustible, Documentos global,
   Alertas, Geocercas, Zonas/Áreas, capacity, SAP/outbox/config → quedan en su grupo colapsado). Validado: los
   12 fuera del menú; cada ficha muestra su «Gestión» (vehículo 10 enlaces, filtrado real 4 filas); 0 errores.
   `trustProxies(at:'*')` agregado en bootstrap/app.php (para acceso por túnel HTTPS: detecta host+scheme reales,
   evita mixed-content; verificado con X-Forwarded-Proto/Host). Acceso móvil = túnel (cloudflared) que corre el
   dueño; el sandbox me bloquea ejecutar binarios externos.

**Unificación "Personal" (pedido del dueño: choferes/ayudantes/usuarios son personas con roles distintos).**
Análisis real: `fleet_drivers` y `fleet_helpers` son casi la MISMA entidad (~14 columnas idénticas: nombre, CI,
nacimiento, teléfonos, dirección, contacto emergencia, referencias, foto, activo; la única diferencia es que
el chofer tiene licencia). `fleet_crews` (Tripulaciones) NO es persona, es una asignación (vehículo+chofer+
ayudantes+fecha). `users` son credenciales (email/pass/roles/2FA) que enlazan a driver_id/node_id; los ayudantes
NO tienen cuenta (no hay rol Ayudante). El dueño eligió **unificar en el ADMIN, sin tocar el dominio** (opción
segura; el merge de dominio Driver+Helper→Person queda como proyecto grande aparte). Hecho: nuevo grupo de nav
**"Personnel"/"Personal"** (es.json +`"Personnel":"Personal"`, collapsed en AdminPanelProvider entre Fleet y
Network); DriverResource/HelperResource/UserResource movidos a ese grupo (getNavigationGroup→__('Personnel')).
Página `App\Filament\Pages\PersonnelDirectory` (/admin/personnel-directory, label "Directorio") = directorio
unificado de personas: une fleet_drivers+fleet_helpers+users con tipo (Chofer/Ayudante/Usuario·<rol>), documento,
contacto, estado (inactivo en rojo) y enlace a la ficha de cada uno; búsqueda por nombre/CI/teléfono/email;
`url()` tolerante view→edit→index. Validado: grupo con [Directorio,Usuarios,Choferes,Ayudantes]; directorio 19
personas con sus 3 tipos y enlaces; 0 errores. **Otros candidatos de duplicación revisados y DESCARTADOS como
falsos**: carrier_settlements vs seller_settlements (dominios distintos: transporte por corredor vs comisión de
marketplace), coverage_zones vs coverage_geo_areas (relacionados pero distintos: zona operativa vs jerarquía
geográfica). Driver/Helper era el único duplicado real tipo "misma cosa, entidades separadas".

DoD de cada cierre: pest verde (venía en **2190 passed / 6178 assertions**), PHPStan 8 = 0, Pint PASS, +
validación en navegador real (login admin@dismac.test/password123, pipeline Playwright
`mcr.microsoft.com/playwright:v1.48.0-jammy`, red `dismac_lg_network`, base http://nginx).

**Consolidación REAL del menú (2026-11-06) — tras feedback del dueño: el merge de dominio (CrewMember, ola 5)
NO había tocado el menú, que seguía mostrando cada rol como ítem CRUD suelto.** Demotados del menú (todos con
hogar, sin orfandad) vía `shouldRegisterNavigation(): false`:
- **Personal → queda SOLO "Directorio"**: UserResource, DriverResource, HelperResource, StoreUserResource
  demotados. `PersonnelDirectory` ya enlaza a cada persona + se le añadieron header actions «gestionar»
  (Choferes/Ayudantes/Usuarios/Asignaciones) a cada índice.
- **Comercio**: SellerResource + CarrierResource demotados (hogar = `PartnersDirectory` «Socios comerciales»);
  CategoryResource demotado (hogar = header action «Categories» en ProductResource = Catálogo).
- **Operaciones**: VehicleRouteResource («Rutas del chofer») demotado (hogar = ViewDriver managementLinks
  `?driver=` + `RequestScopedQuery::by('driver','driver_id')`). Enlaces de presentación cross-context son patrón
  ya aceptado (VehicleRouteResource ya referenciaba ShipmentResource).
Verificado en navegador (sin errores): Personal=1, Comercio sin los 3, Operaciones sin Rutas.
**PENDIENTE de curar (necesitan link filtrado con cuidado por FK anidada, para no dejar huérfanos):** Operaciones
logs Escaneos(ScanEvent, cuelga de delivery.shipment), Stock disponible(StockOnHand, warehouse_id), ASNs(Asn,
seller_id), Paradas planificadas(PlannedStop, shipment anidado), y posiblemente Entregas/Planes; agrupar el trío
de notificaciones en Configuración (B14). DoD menú: PHPStan 0 + Pint PASS + navegador.

**2ª pasada de consolidación del menú (A+B+C, 2026-11-06):**
- **A — logs operativos tucados a su hub (con filtro, sin orfandad):** Escaneos (ScanEvent→envío, filtro
  `whereHas('delivery', shipment_id)`), Paradas planificadas (PlannedStop→envío, `shipment_id`), ASNs
  (Asn→vendedor, `seller_id`; ViewSeller managementLinks). Links añadidos en ViewShipment/ViewSeller.
  **Stock disponible (inventory_lots) NO se tucó**: `warehouse_id` NO es un nodo (verificado: no existe en
  network_nodes) y cruza productos → no tiene entidad-padre limpia; es pantalla operativa real, se deja visible.
- **C — Entregas (Delivery→envío, `shipment_id` directo)** tucada al hub del envío (la cockpit «Tablero de
  última milla» cubre el día a día). GOTCHA: `warehouse_id` ≠ node — no asumir que warehouse es un nodo.
- **B — Configuración reagrupada** (getNavigationParentItem NO existe en este Filament → se usan grupos nuevos):
  «Configuración» 12→5 (Roles, Permisos, Registro de auditoría, Mensajes del outbox, Versiones). Grupos nuevos
  en AdminPanelProvider (collapsed): **Integrations** (Tokens, Integraciones, Traspasos SAP, Mapeos SAP) y
  **Messaging** (es.json `Messaging`→Mensajería; Plantillas, Reglas de cascada, Registro de notificaciones).
Resultado verificado en navegador (0 errores): Operaciones 18→13 (cockpits + Envíos/Stock/Planes/Calendarios),
Config 12→5, +2 grupos técnicos. Total menú ~−14 ítems entre las 2 pasadas. DoD: PHPStan 0 · Pint PASS · es.json
1778 · navegador OK · pest (gate final).
