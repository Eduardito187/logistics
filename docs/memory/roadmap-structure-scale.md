---
name: roadmap-structure-scale
description: "Roadmap (docs/roadmap-estructura-escalabilidad.md) de consolidación de modelo + admin entidad-céntrico + escalabilidad; dónde más aplicar la lógica CRM, por olas"
metadata:
  node_type: memory
  type: reference
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-07T03:50:33.315Z
---

Roadmap vivo en `docs/roadmap-estructura-escalabilidad.md` (2026-11-06), fruto de una auditoría de solo
lectura de los 35 bounded contexts + ~58 resources + la capa cockpit. Tres ejes: **consolidación de modelo**,
**admin CRUD→CRM entidad-céntrico**, **escalabilidad a millones / extraíble a microservicio**.

Hallazgos clave (para no re-auditar):
- **Núcleo ya bien resuelto** (no tocar): outbox (idempotencia/SKIP LOCKED/lease/backoff/dead-letter/índice
  parcial), UUID v7, CQRS donde importa (commerce/search FTS+keyset, DbShipmentListReader), caché de referencia,
  scale indexes por contexto.
- **Deuda real de escala** = (a) capa cockpit `app/Support/Cockpit/*` hace `DB::table()` + JOINs cross-context
  (obstáculo #1 para extraer microservicios → necesita una **proyección de reporting por outbox**, C10), y
  (b) `commerce_orders` sin índices para `ship_to->>'customer_name'` + búsquedas `ilike '%...%'` → seq scan en
  ⌘K / Cliente 360 (C1/C2, crítico; fix con índice de expresión / denormalizar customer_name / pg_trgm GIN).
- **Consolidación de VOs**: `Money` ×8 (shipments usa **float = bug** de redondeo), `GeoPoint` ×7,
  `DateRange`/`TimeWindow` ×4, `PhoneNumber`/Address → extraer a `shared`. **Driver≈Helper** = misma persona
  (UI ya unificada; falta `PersonalProfile` VO + migrar Helper a `fleet_documents`).
- **Entidad-céntrico pendiente**: PhysicalUnit→ShipmentHub, NodeReception/ScanEvent→ficha, capacity→DispatchCalendar,
  Promise→tab en Order, StoreUser→Personal. **Faltan hubs**: Carrier, Seller, Product, Return, Helper. **Directorios**:
  "Socios comerciales" (sellers+carriers), cockpit de liquidaciones, cockpit geográfico (mapa+zonas+cobertura).
- **Falsos positivos marcados (NO hacer)**: Productos vs Catálogo (1 sola resource); carrier vs seller settlements
  (dominios distintos); zonas vs áreas geográficas; DocumentResource (compliance global legítimo); snapshot de
  cliente en execution (ADR 021, deliberado).

Orden recomendado: Ola 1 = índices commerce_orders + pg_trgm + quick wins entidad-céntrico (alto valor/bajo
riesgo); Ola 2 = VOs compartidos (arreglar float); Ola 3 = hubs; Ola 4 = cockpits; Ola 5 = merge dominio
Driver/Helper; Ola 6 = estratégico (proyección de reporting C10 + Customer canónico C11 + particionado telemetry).
Relacionado: [[admin-crm-phases]].

**Ola 1 — IMPLEMENTADA (2026-11-06), DoD verde, sin commitear.** 
- **C1** índices en `commerce_orders`: migración `commerce/orders/…000300_add_read_indexes_to_commerce_orders.php`
  (expresión `((ship_to->>'customer_name'))` + `created_at` + `(status,created_at)`, `CONCURRENTLY IF NOT EXISTS`,
  `$withinTransaction=false`).
- **C2** `pg_trgm` + 11 índices GIN trigram: `database/migrations/…000400_add_trigram_search_indexes.php`
  (tolerante: try CREATE EXTENSION, `Schema::hasColumn`, cada índice en su try). Verificado con EXPLAIN:
  `shipments ... ilike` **usa el índice trigram**. (El `=` exacto en orders usa seq scan sobre datos chicos —
  normal; a escala usa el índice de expresión.)
- **C3** `CustomerHub`: 5 COUNT → 1 `selectRaw` con `count(*) filter(...)`; gasto sumado en SQL con
  `left join lateral jsonb_array_elements(... case jsonb_typeof ...)`; `overview()` cacheado 60s
  (`Cache::remember`, forma primitiva, método `compose()` separado).
- **C4** `PersonnelDirectory::userRoles(array $userIds)` ahora `whereIn` acotado a los ids de la página.
- **B15** (fork) 9 resources de alto volumen: `->deferLoading()` + `paginationPageOptions([25,50,100])` + filtro
  **"Date range"** con default últimos 30 días (ScanEvent/ShipmentTracking/NodeReception/AuditLog/OutboxMessage/
  Notification/EmittedLabel/TelemetryAlert; StockOnHand solo defer). Gotcha: TrackingRecord/NotificationRecord sin
  `@property` de su columna de fecha → se usó `whereRaw` (ideal: agregar @property a esos 2 modelos — pendiente menor).
- **B12** (fork) rename "Settlements"(carrier)→"Carrier settlements" (es.json "Liquidaciones de transportista").
- **B1** (fork) PhysicalUnit "Paquetes" fuera del menú + en «Gestión del envío» del ShipmentHub (filtrado por
  `shipment_id`) + deferLoading. **B5** (fork) StoreUser → grupo "Personal".
Gotcha migración: `use Throwable;` en archivo de migración (namespace global) es FATAL ("has no effect") → no
importar, usar `Throwable` directo.

**Ola 2 (VOs compartidos) — RECONSIDERADA / NO-GO (decisión del dueño 2026-11-06).** Al leer el código real:
los VOs Money/GeoPoint/etc. están **deliberadamente separados por contexto** (docblocks explícitos: "propio del
contexto, aislamiento DDD, no se importa de otro bounded context" — coherente con microservicios), han DERIVADO
en comportamiento (planning usa latitude/longitude y sin equals; telemetry compara con 1e-9 vs === estricto; cada
uno lanza su excepción de contexto), y el "float de shipments" NO es bug (es precio de PRESENTACIÓN con round(,2),
docblock lo dice). DRY no justifica romper el aislamiento. Un shared kernel sería una decisión de arquitectura
con ADR propio, no un refactor mecánico. **Lección: leer la implementación antes de clasificar un "duplicado" —
la auditoría lo sobre-marcó.**

**BUG IMPORTANTE DESCUBIERTO Y CORREGIDO (2026-11-06):** el deep-link `?tableFilters[<f>][value]=X` de Filament
v4 **NO hidrata el filtro desde la URL** en esta versión (verificado: maintenance total=4, "filtrado"=4). Afectaba
a TODOS los enlaces «Gestión de <entidad>» de Olas previas (vehículo→odómetro/mantenimiento/etc., envío→paquetes,
seller→liquidaciones) — abrían la lista SIN filtrar. Mis validaciones previas de "filteredRows=4" fueron
COINCIDENCIA (4=total). **Fix (patrón que SÍ funciona):** helper `App\Filament\Support\RequestScopedQuery::by(param,
column)` → `->modifyQueryUsing(RequestScopedQuery::by('vehicle','vehicle_id'))` en el recurso + enlace
`...getUrl('index').'?vehicle='.rawurlencode($id)`. Verificado REAL: maintenance ?vehicle=X → 1 de 4. Aplicado a:
MaintenanceOrder/Plan, Odometer, Refuel, Departure, Expense, Trip (vehicle); PhysicalUnit (shipment);
SellerSettlement (seller); Tariff/Settlement (carrier, via `?carrier=`, lo hizo el fork). **Lección: validar el
filtrado contando filtradas vs total con un subconjunto conocido (no un número que casualmente coincida).**

**Ola 3 — PARCIAL (2026-11-06), DoD verde, sin commitear.** Hecho: **B6 CarrierHub** (ViewCarrier cockpit +
CarrierHub service + recordUrl + Tarifas/Settlement demotados y filtrados por ?carrier=) [fork]; **B7 SellerHub**
(ViewSeller + SellerHub + SellerSettlement demotado filtrado ?seller=) [fork]; **B11 directorio "Socios
comerciales"** (`app/Filament/Pages/PartnersDirectory.php` + blade, une carriers+sellers con tipo/NIT/estado/
enlace a hub; grupo Comercio); **B2** NodeReception→ficha del envío; **B4** Promise→ficha del pedido. ScanEvent
queda como log. **Diferidos (avisados): B3** capacity→DispatchCalendar, **B9** Return hub, **B10** Helper hub.
**Ola 3 — COMPLETA (2026-11-06), DoD verde, sin commitear.** Todo B2–B11 + filtrado + diferidos:
- **Hubs 360** (de CRUD plano a cockpit con «Gestión de <entidad>» filtrada): B6 Carrier, B7 Seller, B9 Return
  (ViewReturn + ReturnHub + getTabs por estado; es.json +`Initiated`/`Closed`), B10 Helper (ViewHelper +
  HelperHub + notas). Cada uno: ViewX cockpit + read-service `app/Support/Cockpit/<X>Hub.php` + blade +
  `recordUrl`.
- **B11** directorio "Socios comerciales" (`PartnersDirectory` + blade, carriers+sellers, grupo Comercio).
- **B2** NodeReception→envío · **B4** Promise→pedido (join flojo → al índice) · **B3** CapacityBucket+BlackoutDay
  fuera del menú, hogar en DispatchCalendar (acciones "avanzado").
- **Filtrado completo** de TODOS los enlaces «Gestión»: helper `App\Filament\Support\RequestScopedQuery`
  (`by`/`byAny`/`touching`/`ownedBy`) + `modifyQueryUsing` en ~15 recursos + URLs `?vehicle=/?driver=/?node=/
  ?order=/?shipment=/?helper=/?carrier=/?seller=`. `DocumentResource` = UN solo modifyQueryUsing para
  vehicle/driver/helper (owner polimórfico, whereRaw). **Verificado contando subconjuntos**: node→tramos 3/8,
  vehículo→mantenimiento 1/4, helper→documentos 8→1. GOTCHA larastan: `@var Builder<Model>` solo va ANTES de una
  asignación, no de un `foreach`. GOTCHA UX: recursos con filtro de fecha default (B15) + filtro de entidad
  muestran "recientes de esa entidad" (EmittedLabel, NodeReception) — razonable, anotado.
**Ola 1 sin pendiente real** (el whereRaw del fork es válido).

**Ola 4 — Cockpits unificados — COMPLETA (2026-11-06), DoD verde, sin commitear.**
- **B13 Cockpit geográfico**: `CoverageMap` (página) pasó a ser el cockpit único "Geography" (es.json `Geography`→Geografía):
  capa de zonas + nueva **capa de nodos conmutable** (`L.control.layers`, marcadores de `network_nodes` con
  popup→ficha 360 `NodeResource::view`), editor de zonas intacto, header actions «Coverage Zones»/«Geographic
  Areas» (CRUD avanzado). Demotados del menú: `NodeMap` (página), `ZoneResource`, `GeoAreaResource`. Verificado
  en navegador: `#coverage-map` + 2 toggles + título "Geografía".
- **B12 Cockpit de liquidaciones** [fork]: `app/Filament/Pages/SettlementsCockpit.php` (grupo Commerce, español
  directo "Liquidaciones") + `app/Support/Cockpit/SettlementsBoard.php` (1 GROUP BY con count/sum filter por
  estado, nombres legibles vía `carriers`.name / `sellers`.legal_name, listas enlazadas a cada ViewX) + blade.
  Tablas reales: `carrier_settlements` (amount_cents; submitted/approved/paid/disputed) y `seller_settlements`
  (net_cents; draft/confirmed/paid). «Por pagar» = approved/confirmed; disputed en rojo. Verificado: 8 KPIs.
  GOTCHA phpstan8: `selectRaw` exige `literal-string` → construir el SQL con `.=` sobre whitelist literal, no `implode`.
- **B8 ProductHub** [fork]: `ViewProduct` pasó a cockpit (`$view=filament.catalog.product-hub` + `#[Computed] hub()`)
  + `app/Support/Cockpit/ProductHub.php` + blade + `recordUrl` en ProductResource. La LLAVE común es el SKU
  (canónico + de cada variante, `upper(sku)`): stock real por `inventory_lots`, precios por `commerce_price_list_items
  ⋈ commerce_price_lists`, órdenes recientes por `commerce_orders` + `cross join lateral jsonb_array_elements(lines)`
  (units/subtotal solo de las líneas de ese producto; orden identificada por `ship_to->>'customer_name'`+fecha, sin
  UUID). Hijos mostrados INLINE (no hay resource-lista filtrable por SKU → no enlaces huérfanos). Honesto: sin
  productos sembrados no se pudo confirmar el render con datos en navegador; verificado end-to-end por tinker.
Los `ERR_CONNECTION_REFUSED` del mapa en validación = teselas del tileserver self-host (no arriba en ese entorno),
comportamiento CERO-CDN esperado, no regresión.

**Ola 5 — Consolidación de dominio — COMPLETA (2026-11-06), DoD verde, sin commitear.** Decisión del dueño:
hacer LOS 4 PUNTOS pese a que la lectura del código mostró que A5 era en parte falso positivo y el merge
degrada invariantes. Lo hice por fases ADITIVAS, no destructivas, ids preservados. Hallazgo clave (del mapa de
blast radius): **ningún contexto fuera de fleet usa los VO tipados `DriverId`/`HelperId`** — el acoplamiento
cross-context es siempre por UUID string crudo (`driver_id`, `helper_ids`, `users.driver_id`), así que preservar
los UUID de las filas existentes protege execution/fleetops/telemetry/app.
- **Fase 1 · `PersonalProfile` VO** (`fleet/.../ValueObject/PersonalProfile.php`): los 8 campos personales
  comunes (foto, CI, nacimiento, tel. secundario, dirección, emergencia ×2, referencias). `DriverProfile`/
  `HelperProfile` exponen `personal()`; `HelperProfile.toArray()` delega (byte-idéntico). DRY real sin tocar DB.
- **Fase 2 · A5 documentos de Helper → `fleet_documents`**: migración aditiva idempotente de backfill
  (`fleet/.../2026_11_06_000500_backfill_helper_inline_documents_to_fleet_documents.php`, solo INSERT, guarda el
  jsonb inline como respaldo; dedup por owner+tipo+vencimiento). CRÍTICO: el backfill introducía DOBLE CONTEO en
  las alertas (leían inline + fleet_documents) → se quitó el loop inline de Helper en `AlertExpiringDocumentsHandler`
  y `ListExpiringDocumentsHandler` (los docs de ayudante salen solo de fleet_documents; se conserva la carga de
  helpers para las etiquetas). `RegisterHelperHandler` ahora escribe los docs del alta a fleet_documents (no inline),
  inyecta `DocumentRepository`. `HelperView.documents` se repunta a fleet_documents (param opcional en `fromDomain`;
  `ListHelpersHandler` arma el mapa owner→docs de una pasada). El jsonb `fleet_helpers.documents` queda vestigial
  (no destructivo). NOTA: Vehicle usa el MISMO patrón inline (no migrado, pendiente de consistencia futura).
- **Fase 3 · agregado `CrewMember(role)`**: unificación ADITIVA montada sobre las tablas existentes (sin esquema
  nuevo, ids preservados). `CrewRole` (ya existía), `CrewMemberId` (= UUID del driver/helper), agregado
  `CrewMember` con **invariante de rol EXPLÍCITA** (driver⇒licencia+expiry; helper⇒sin licencia ni vehículos →
  así el merge se hizo BIEN, no con nullables sueltos) + factorías `fromDriver`/`fromHelper`. `CrewMemberRepository`
  + `EloquentCrewMemberRepository` (delega en Driver/Helper repos, reutiliza mappers). Read model `CrewMemberView`
  + query `ListCrewMembers(role?, onlyActive?)` + handler. Comando unificado `RegisterCrewMember` + handler que
  **delega por rol** a RegisterDriver/RegisterHelper vía CommandBus (sin duplicar lógica ni eventos; valida la
  invariante en el borde). Bindings en `FleetServiceProvider`. NO se repuntó `PersonnelDirectory` (ya unifica por
  UI con `DB::table`, escalable — respeta la Ola 1). Driver/Helper siguen existiendo (no destructivo). Tests:
  `CrewMemberTest` (unit, 3) + `CrewMemberUnificationTest` (feature, 3: alta unificada, filtro por rol, invariante).

**Ola 6 — Escala profunda / estratégico — COMPLETA (2026-11-06), DoD verde, sin commitear.** Decisión del
dueño: hacer TODA la Ola 6 (C5–C11) pese a que advertí con datos duros que es mayormente prematura (volúmenes
reales diminutos: 151 pings, 521 escaneos, 295 envíos → los disparadores "cuando el volumen lo pida" NO se
dispararon). Se hizo todo de forma ADITIVA y respetando la regla dura NO-DESTRUCTIVA.
- **C5 keyset envíos** [fork]: `DbShipmentListReader::listKeyset(limit, afterId?)` (where id>cursor + orderBy id +
  limit+1, sin OFFSET ni COUNT), `ShipmentPage` read model, query `ListShipmentsByCursor`, controller `?cursor=`
  (token opaco base64). La rama `?page=` offset se CONSERVA (contrato E-02 testeado con total/total_pages).
- **C6 partición + BRIN**: el BRIN ya existía en AMBAS tablas (trabajo de escala previo). Partición de
  `telemetry_pings` por rango de `recorded_at` PRESERVANDO DATOS (migración `...000600`: LIKE + PK compuesta
  (id,recorded_at) + DEFAULT + 6 mensuales, copia con verificación de conteo, atómica, idempotente; verificado:
  151 filas preservadas, 7 particiones hoja). GOTCHA (lo cazó el fork C5): `ALTER TABLE <padre> SET
  (autovacuum...)` FALLA en tabla particionada → los params van en cada partición HOJA. **`execution_scan_events`
  NO se particiona**: su `UNIQUE(idempotency_key)` tendría que incluir la clave de partición → rompería la
  idempotencia offline. Bloqueo de correctitud, documentado; el BRIN ya da el barrido por rango.
- **C7 retención**: ya existían prune de pings/outbox/audit-log. Añadido `logistics:execution:prune-scan-events`
  (dry-run por defecto, --force, chunked por occurred_at, reusa `RowPruner` compartido) + config
  `logistics-execution.prune` + test. Ninguna poda se agenda (retención manual/ops).
- **C8 broadcast por nodo** [fork]: `VehiclePositionUpdated::broadcastOn()` añade `fleet.node.{homeBaseNodeId}`
  además del `fleet` global (torre); gating reusa el de `fleet`. Broadcast dual (front migra cuando quiera).
- **C9 pump daemon** [fork]: `logistics:outbox:pump-daemon` (bucle continuo, reusa EnqueueOutboxBatch/SKIP LOCKED,
  SIGTERM, --max-iterations/--max-seconds, N workers). El cron everyMinute queda de red de seguridad.
- **C10 proyección de reporting por outbox** [fork]: MECANISMO + corte representativo. `reporting_shipments`
  (desnormalizada), `ReportingShipmentsProjector` enganchado al dispatcher del outbox existente (routes/fanout de
  logistics-outbox, longest-prefix; preserva notificaciones), backfill `logistics:reporting:backfill-shipments`,
  reader `ShipmentReporting`, y `OperationsBrief::activeShipments()` repuntado a la proyección CON FALLBACK a la
  tabla viva. Camino de extensión (resto de lecturas del cockpit) documentado. Es la pieza que habilita extraer
  microservicios.
- **C11 Customer canónico** [fork]: proyección `customer_profiles` (PK = documento normalizado CI/NIT) en
  `app/Customers/` (proyección de lectura, no package nuevo — evita ceremonial de composer/providers). VO
  `CustomerId` (normaliza documento), read model, `CustomerDirectory` reader, `CustomerProjector::rebuild()`
  (1 SQL: DISTINCT ON por documento, upsert idempotente) + comando `logistics:customers:rebuild`, enlace aditivo
  `CustomerHub::canonicalByDocument()`. HONESTO: solo se puebla desde `shipments` (tienen customer_document_id);
  `commerce_orders` NO tienen documento → no se enlazan (sería inventar identidad); documentado. El hook de evento
  (`touch()`) está listo pero sin suscriptor registrado (extensión, no roto).

**Olas 1–6 COMPLETAS. Roadmap docs/roadmap-estructura-escalabilidad.md agotado.** Sin commitear (lo hace el dueño).

**SIMPLIFICACIÓN de la deuda de Olas 5/6 — HECHA (2026-11-06, decisión del dueño "retirar/simplificar").**
Tras completar 5/6 el dueño pidió retirar lo prematuro que no aporta al volumen real, SIN DB destructiva.
RETIRADO (muerto/alterno/extra aditivo, cero regresión; PHPStan 0, Pint PASS):
- **CrewMember (Ola 5 fase 3)**: borrado el agregado + CQRS + repo + readmodel + `CrewRole` enum (quedó muerto) +
  2 tests. Nunca tuvo UI (solo se auto-testeaba). Se CONSERVAN `PersonalProfile` VO y la unificación de documentos
  de Helper→`fleet_documents` (fases 1 y 2): son mejoras reales y cableadas, no deuda. Limpiadas 3 menciones en
  docblocks de los VOs de perfil + bindings/handlers en `FleetServiceProvider`.
- **C9 pump-daemon**: borrado comando + test + config `daemon_sleep_ms` + registro en `OutboxServiceProvider` +
  comentario en routes/console. El schedule real usa `logistics:outbox:pump` (red de seguridad), el daemon era un
  alterno por supervisor nunca usado.
- **C10 proyección reporting**: borrado `ReportingShipmentsProjector` + `ShipmentReporting` + comando backfill +
  test; `OperationsBrief::activeShipments()` simplificado a lectura directa de `shipments` (ya tenía ese fallback);
  quitadas rutas/fanout de los 3 `shipments.*` en `logistics-outbox.php` (vuelven al prefijo `shipments.`→
  notificación). La tabla `reporting_shipments` se CONSERVA (regla no-destructiva; migración aditiva queda).
- **C11 Customer canónico**: borrado `app/Customers/` completo + test + comando rebuild + `CustomerHub::
  canonicalByDocument()` (único consumidor, era aditivo). El Cliente 360 lee de fuentes directas, intacto. La tabla
  `customer_profiles` se CONSERVA (no-destructiva).
CONSERVADO con justificación (retirarlo violaría reglas duras o regresaría features):
- **C6 partición telemetry_pings**: revertir = des-particionar = **operación destructiva de DB (prohibida)**.
- **C5 keyset**: es **contrato público de API** (`/app/shipments` con `?cursor=`, openapi v1) que consume la app
  Flutter; quitarlo cambiaría el contrato. Dual-mode: offset sigue.
- **C8 canal `fleet.node`**: parte del diseño de broadcasting Fase 4 (ADR 016), latente pero coherente.
- **C7 retención `prune-scan-events`**: NO es prematuro — ya existen 3 prune (audit-log/outbox/telemetry-pings);
  completa el patrón para la tabla de MAYOR volumen (escaneos). Retirarlo dejaría justo esa tabla sin purga.
**Flota 360**: ya estaba construido y 100% engranado de olas previas (ViewVehicle agrega todo, 10 enlaces de
gestión filtrados por `?vehicle=`/`byAny`). Único ajuste CRM: `DriverHistory` sale del menú y se alcanza desde el
hub del chofer (`?driver=` + managementLink "Desempeño operativo").
