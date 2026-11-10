# Roadmap — Estructura, consolidación y escalabilidad

> Estado: propuesta (2026-11-06). Basado en una auditoría de solo lectura de los 35 bounded contexts
> (26 `logistics/*` + 9 `commerce/*`), ~58 resources Filament y la capa de lectura del cockpit.
> **Nada de este documento está implementado todavía**; es el plan priorizado para ejecutar por olas.

## 0. Para qué es esto

El admin dejó de ser un CRUD plano y es un cockpit CRM (Fases 1–5: nav por secciones, fichas 360,
segmentos, líneas de tiempo, Cliente 360, Personal unificado, tiempo real, analítica). Este roadmap
extiende esa misma lógica al **resto del proyecto**, en tres ejes:

1. **Consolidación de modelo** — "la misma cosa modelada como entidades/VOs separados" → unificar.
2. **Entidad-céntrico (CRM, no CRUD)** — lo que depende de una entidad vive dentro de su ficha; lo que es
   un tercero/persona se gestiona en un directorio unificado.
3. **Escalabilidad** — la meta del proyecto (CLAUDE.md, ADR 001/008) es responder rápido sobre **millones
   de registros**, con procesos transaccionales simultáneos y cada contexto **extraíble a microservicio**.

### Veredicto honesto de la auditoría

- **El núcleo está muy bien resuelto** y no hay que tocarlo: outbox transaccional (idempotencia por
  `event_id`, `FOR UPDATE SKIP LOCKED` + lease, backoff/dead-letter, índice parcial de despachables),
  UUID v7 en todo id, CQRS con read-models donde importa (`commerce/search` con FTS GIN + keyset,
  `DbShipmentListReader`), caché de referencia extensa (regla 7, forma primitiva), e índices de escala ya
  añadidos por contexto. **No inventamos problemas ahí.**
- **La deuda de escala real se concentra en dos lugares**: (a) la **capa de lectura transversal del
  cockpit** (`app/Support/Cockpit/*`), que hace `DB::table()` y JOINs cross-context directos, y (b)
  `commerce_orders` + las búsquedas por `ilike '%…%'`, que a millones hacen seq scan en los hot-paths
  interactivos (⌘K, Cliente 360, colas de triage).
- **La consolidación de modelo** es sobre todo calidad/mantenibilidad (Value Objects duplicados), con un
  bug real escondido (el `Money` de shipments usa `float`).

### Principios de ejecución (no negociables)

- **No se pierde funcionalidad**: "sacar del menú" = mover a su ficha/directorio, siempre accesible.
- **Por etapas y aditivo**: migraciones aditivas e idempotentes; nunca `migrate:fresh`/`wipe`.
- **DoD por ítem**: tests verdes + PHPStan 8 + Pint + validación real en navegador. Reportar números.
- **Marcar falsos positivos**: hay cosas que *parecen* duplicadas y no lo son — no tocarlas (§6).
- **El dueño commitea**: ningún commit/push sin pedido explícito.

---

## 1. Eje A — Consolidación de modelo (Value Objects + entidades)

| # | Qué | Dónde (evidencia) | Propuesta | Riesgo / Esfuerzo |
|---|-----|-------------------|-----------|-------------------|
| A1 | **`Money` VO duplicado ×8** — y el de shipments usa `float`, no centavos (riesgo de redondeo) | `logistics/{carriers,settlement,sellers,inventory,fleetops}/…/Money.php`, `commerce/{payments,pricing}/…/Money.php`, `logistics/shipments/…/Money.php` (**float**) | Extraer `Logistics\Shared\Domain\ValueObject\Money` (cents+currency, inmutable). **Primero arreglar el float de shipments** (revisar persistencia). Commerce puede mantener el suyo si se quiere el límite estricto. | Bajo / Medio (por el caso float) |
| A2 | **`GeoPoint` VO duplicado ×7** + columnas `decimal(lat,10,7)/lng` repetidas | `logistics/{tracking,shipments,network,telemetry,execution,planning,coverage}/…/GeoPoint.php` | Extraer `Shared\…\GeoPoint`. Estandarizar la columna/índice espacial (PostGIS `geography`+GIST) donde haya búsqueda por cercanía (hoy solo `network_nodes` lo tiene). | Bajo / Medio |
| A3 | **`DateRange`/`TimeWindow` dispersos ×4** (SettlementPeriod, BillingPeriod, 2× TimeWindow) | `logistics/{settlement,carriers,planning,coverage}/…` | Extraer `Shared\…\DateRange` + `Shared\…\TimeWindow`; `BillingPeriod` queda como wrapper del formato YYYY-MM. | Bajo / Bajo |
| A4 | **`PhoneNumber` solo en fleet; Address en 3 formas** | `logistics/fleet`, `logistics/shipments/…/Address.php`, `commerce/accounts/…/Address.php`, `commerce/orders/…/ShippingAddress.php` | Promover `PhoneNumber` a `shared`. Address: evaluar VO base, **sin forzar** (la de shipments carga geocoding/confidence — es legítimamente más rica). | Bajo / Bajo |
| A5 | **Documentos del dueño: polimórfico bien hecho en fleet, inline jsonb en otros** | `fleet_documents(owner_type,owner_id)` + `OwnerDocuments.php` (bien); **Helper** guarda `documents` jsonb inline; `carrier_settlements`/`seller_settlements` igual | Unificar bajo un `OwnerDocuments` compartido (shared) reutilizable por fleet/carriers/sellers; migrar Helper a `fleet_documents`. | Bajo-Medio / Medio |
| A6 | **Driver ≈ Helper = misma Persona** (dominio aún separado) | `fleet_drivers` y `fleet_helpers` (~14 columnas idénticas); `Driver.php`/`Helper.php` | La **UI ya se unificó** (grupo Personal + directorio). A nivel dominio: VO/trait `PersonalProfile` compartido (foto, CI, nacimiento, teléfonos, dirección, emergencia, referencias); licencia queda de Driver. Opción mayor: agregado `CrewMember` con `role`. | Medio / Medio-Alto |

**Nota de valor:** A1–A4 son bajo riesgo y mejoran consistencia/mantenibilidad; **A1 arregla un bug real
de dinero**. A5–A6 tocan persistencia → por etapas, con migración aditiva + backfill.

---

## 2. Eje B — Admin CRUD→CRM (entidad-céntrico + unificación)

Patrón ya probado: `shouldRegisterNavigation()=false` + sección «Gestión de <entidad>» en la ficha 360 del
padre, con enlaces a la lista filtrada. Lo que falta aplicarlo:

### 2.1 Detalle que debe vivir dentro de su entidad

| # | Recurso suelto | Dónde | Hogar propuesto | Riesgo/Esfuerzo |
|---|----------------|-------|-----------------|-----------------|
| B1 | **PhysicalUnit ("Packages")** | `shipments/…/PhysicalUnitResource.php` | ShipmentHub (como ya se hizo con líneas/etiquetas). Revisar label contra la regla de etiquetado (nombre+SKU, nunca "bulto"). | Bajo / Bajo |
| B2 | **NodeReception + ScanEvent** | `execution/…/{NodeReceptionResource,ScanEventResource}.php` | Secciones en ShipmentHub y/o NodeHub (ScanEvent es más un log → ver escalabilidad C). | Bajo / Bajo-Medio |
| B3 | **CapacityBucket + BlackoutDay** | `capacity/…/{CapacityBucketResource,BlackoutDayResource}.php` | Gestionar desde la page `DispatchCalendar` (o la ficha del Nodo: el cupo es por nodo/día). | Medio / Medio |
| B4 | **Promise ("Delivery promises")** | `promise/…/PromiseResource.php` | Pestaña/timeline dentro de OrderHub/ShipmentHub (es un registro transaccional del pedido, no un hub propio). | Bajo / Medio |
| B5 | **StoreUser mal ubicado** (está en "Red") | `app/Filament/Resources/StoreUserResource.php` | Mover a **Personal** (es gestión de cuentas usuario↔nodo-tienda) o sección del NodeHub. | Bajo / Bajo |

### 2.2 Hubs 360 que faltan (CRUD plano que los merece)

| # | Entidad sin hub | Dónde | Qué agregaría el hub | Riesgo/Esfuerzo |
|---|-----------------|-------|----------------------|-----------------|
| B6 | **Carrier** | `carriers/…/CarrierResource.php` | Hub con sus envíos + **Tarifas** + **Liquidaciones** adentro (hoy `TariffResource`/`SettlementResource` sueltos en Commerce). | Bajo / Medio |
| B7 | **Seller** | `sellers/…/SellerResource.php` | Hub con productos + ventas + **SellerSettlement** adentro + documentos. | Bajo / Medio |
| B8 | **Product** | `commerce/catalog/…/ProductResource.php` | Hub con stock, categorías, órdenes, precios. | Medio / Medio |
| B9 | **Return** (RMA) | `returns/…/ReturnResource.php` | Hub o, mínimo, `getTabs` por estado (tiene ciclo de vida propio). | Bajo / Medio |
| B10 | **Helper** | `fleet/…/HelperResource.php` | Reutilizar el patrón `DriverHub` (misma persona operativa). | Bajo / Medio |

### 2.3 Directorios y cockpits unificados (estilo PersonnelDirectory)

| # | Unificación | Qué junta | Riesgo/Esfuerzo |
|---|-------------|-----------|-----------------|
| B11 | **Directorio "Socios comerciales"** | Sellers + Carriers (terceros externos), con cross-link a sus hubs (B6/B7). | Medio |
| B12 | **Cockpit de liquidaciones** | `SettlementResource` (carrier) + `SellerSettlementResource` (seller) en una vista financiera con pestañas. Renombrar "Settlements"→"Carrier settlements" (hay colisión de nombres, key sin traducir). | Bajo (rename) / Medio (cockpit) |
| B13 | **Cockpit geográfico con capas** | `CoverageMap` (editor de polígonos de Zones) + `NodeMap` + `ZoneResource`/`GeoArea`: un solo mapa con capas nodos/zonas/cobertura; Zone/GeoArea pasan a gestionarse dentro del mapa. | Medio-Alto |

### 2.4 Configuración

| # | Qué | Propuesta | Riesgo/Esfuerzo |
|---|-----|-----------|-----------------|
| B14 | **Trío de notificaciones suelto** (`MessageTemplate`, `MessageTypeRule`, `Notification` log) | Agrupar como subsistema "Notificaciones" (plantillas+reglas = config; el log = vista de solo lectura, alto volumen). | Bajo / Bajo |

### 2.5 Escalabilidad de la UI (millones de filas)

Tablas de crecimiento ilimitado sin ventana temporal por defecto; Filament hace `COUNT(*)` por carga.
Patrón reutilizable: `->deferLoading()` + **filtro de fecha persistido con default** (p.ej. últimos 7/30
días) + `paginationPageOptions([25,50])` sin "all" + confirmar índice en la columna de `defaultSort`.

| # | Tablas a tratar |
|---|-----------------|
| B15 | `ScanEvent` (la más crítica), `ShipmentTracking`, `NodeReception`, `StockOnHand`, `AuditLog`, `OutboxMessage`, `Notification`, `PhysicalUnit`, `EmittedLabel`, `TelemetryAlert` |

---

## 3. Eje C — Escalabilidad & arquitectura

### 3.1 Cuellos de botella de lectura (hot-paths interactivos)

| # | Qué | Dónde | Impacto a millones | Propuesta | Prioridad |
|---|-----|-------|--------------------|-----------|-----------|
| C1 | **`commerce_orders` sin índices para cómo lo leen los cockpits** | migración `commerce/orders/…_create_commerce_orders_table.php` (solo indexa `customer_id`,`status`); lo leen `CustomerHub`, `GlobalSearch`, `AttentionQueues::paidWithoutShipment` | `ship_to->>'customer_name'` = extracción JSON → **seq scan + sort** en cada Cliente 360 / ⌘K / cola | Índice de expresión `((ship_to->>'customer_name'))` + `created_at` / `(status,created_at)`. Mejor: **denormalizar `customer_name` a columna real** (como shipments) e indexar. | **Crítico** / Bajo |
| C2 | **Búsqueda con `ilike '%term%'`** (comodín a la izquierda → seq scan por tecla) | `GlobalSearch.php` (shipments, fleet_vehicles/drivers, carriers, nodes, commerce_orders), `PersonnelDirectory.php` (drivers/helpers/users) | ⌘K hace full scan de shipments y orders en cada pulsación | Habilitar `pg_trgm` + `GIN (col gin_trgm_ops)`; o **proyección de búsqueda unificada** (FTS GIN + keyset, como `commerce/search`) alimentada por outbox. | Alto / Bajo (trgm) · Medio-Alto (proyección) |
| C3 | **`CustomerHub` = N consultas + suma JSON en PHP, sin caché** | `app/Support/Cockpit/CustomerHub.php` (`metrics()` ~5 COUNT/MAX; `spend()` trae todas las órdenes y suma `lines` en PHP) | ficha recalculada en cada vista; materializa todo el historial | Colapsar a 1 `GROUP BY`; sumar gasto en SQL (`jsonb_array_elements` o total denormalizado); cachear overview por cliente (TTL corto, primitivo). | Alto / Medio |
| C4 | **`PersonnelDirectory::userRoles()` carga toda `user_roles` sin WHERE** | `app/Filament/Pages/PersonnelDirectory.php` | crece con toda la tabla; unión en PHP | `whereIn('user_id', idsDeLaPágina)`; si crece, proyección unificada de personas. | Medio / Bajo |
| C5 | **Paginación por OFFSET en el listado de envíos** | `shipments/…/DbShipmentListReader::list()` | offset profundo escanea y descarta | Keyset por `id` (UUID v7 ordenable), como `commerce/search`. | Medio / Medio |

### 3.2 Volumen append-only (telemetría / escaneos)

| # | Qué | Dónde | Propuesta | Prioridad |
|---|-----|-------|-----------|-----------|
| C6 | **Sin particionado temporal ni BRIN** en tablas de altísimo volumen | `telemetry/…_create_telemetry_pings_table.php`, `execution/…_create_execution_scan_events_table.php` | Particionado declarativo por rango de `recorded_at` + **índice BRIN** en `recorded_at` (compacto para series temporales). | Medio (cuando el volumen GPS lo pida) |
| C7 | **Confirmar política de retención/archivado** de pings y scan_events | — | Retención/archivado para que no crezcan sin techo (el outbox ya lo tiene). | Medio |

### 3.3 Tiempo real a escala

| # | Qué | Dónde | Propuesta | Prioridad |
|---|-----|-------|-----------|-----------|
| C8 | **Canal `fleet` único y global** | `routes/channels.php` + `VehiclePositionUpdated::broadcastOn()` | A miles de vehículos, cada espectador recibe el stream completo. Añadir **canales por nodo/zona/departamento** (coherente con la regla de ruta de un solo departamento); `fleet` solo para la torre global. | Medio (flota hoy es chica) |
| C9 | **Pump del outbox = 1 instancia cada minuto** | `routes/console.php` (`everyMinute()->withoutOverlapping()`) | SKIP LOCKED soporta N pumps; hoy hay piso de latencia ~1 min. Cuando pida: daemon continuo o varios workers. | Medio-Bajo / Bajo |

### 3.4 Arquitectura — habilitar la extracción a microservicios

| # | Qué | Dónde | Propuesta | Prioridad |
|---|-----|-------|-----------|-----------|
| C10 | **El único obstáculo serio para extraer contextos es la capa cockpit** (los dominios SÍ están desacoplados vía bus+outbox) | `app/Support/Cockpit/*` hace `DB::table()` + JOINs cross-context (`AttentionQueues` une execution↔shipments; `CustomerHub` une surveys↔shipments y lee commerce_orders; `EntityTimeline`, `NodeHub`, etc. leen 14+ tablas de varios contextos) | **Read-model / almacén de reporting dedicado**, poblado por los eventos que ya viajan por el outbox (CQRS read-side transversal). El cockpit lee de esa proyección única → cada contexto queda extraíble sin tocar el cockpit. | **Alto (estratégico)** / Alto |
| C11 | **No existe agregado/contexto Customer; la identidad del cliente es un string disperso** | `shipments.customer_name` + `commerce_orders.ship_to->>'customer_name'` son la "clave" del Cliente 360 | Proyección/contexto **Customer con id canónico** (documento/CI-NIT), poblado por eventos; shipments/orders lo referencian. Resuelve a la vez C1, C2, C3 y el frente de cliente de C10. (El snapshot anti-fraude de execution, ADR 021, se mantiene: es deliberado.) | Medio-Alto / Alto |

---

## 4. Roadmap por olas (priorizado por valor/riesgo)

> Cada ítem cierra con el DoD estándar (pest + PHPStan 8 + Pint + navegador) y sin commitear.

### Ola 1 — Rendimiento de lectura + quick wins (bajo riesgo, ROI inmediato)
- **C1** índices aditivos en `commerce_orders` (expresión `customer_name` + `created_at`/`(status,created_at)`). *1 migración.*
- **C2** `pg_trgm` + GIN trigram en las columnas que se buscan por `ilike`.
- **C4** `whereIn` acotado en `PersonnelDirectory::userRoles`.
- **C3** colapsar los COUNT de `CustomerHub::metrics` a un `GROUP BY`, sumar gasto en SQL, cachear overview.
- **B15** barrido de UI: `deferLoading` + filtro de fecha por defecto en las tablas de logs de alto volumen.
- **B1** PhysicalUnit → ShipmentHub · **B5** StoreUser → Personal · **B12 (rename)** "Carrier settlements" · **B14** agrupar notificaciones.

### Ola 2 — Value Objects compartidos — **RECONSIDERADA / NO-GO (2026-11-06)**
Al leer las implementaciones reales, la consolidación de VOs (A1–A4) **choca con el diseño explícito del
código** y deja de ser el "refactor puro y de bajo riesgo" que asumió la auditoría:
- **Aislamiento deliberado**: 5 archivos de VO documentan explícitamente que son locales al contexto
  (*"propio del contexto (aislamiento DDD): no se importa el GeoPoint de otro bounded context"*,
  *"deliberadamente local… para que el dominio siga siendo PHP puro e independiente"*). Es coherente con la
  meta de **microservicios autónomos** (ADR 001/008).
- **Han derivado en comportamiento**: `planning` usa `latitude/longitude` (no `lat/lng`) y no tiene
  `equals/toArray`; `telemetry` compara con tolerancia `1e-9` vs `===` estricto en los demás; cada uno lanza
  **su excepción de contexto**. Un VO compartido tendría que elegir una semántica y romper la de algún contexto.
- **El "`float` de shipments" NO es un bug**: su docblock dice que es un **precio de presentación** (mail al
  cliente), con `round(,2)`, que no cobra ni valida contra SAP. Es un valor liviano intencional.

**Decisión del dueño (2026-11-06): respetar el aislamiento, no consolidar.** Si en el futuro se quiere un
*shared kernel* (DRY sobre aislamiento estricto), es una decisión de arquitectura propia, con su ADR y
armonización cuidadosa del drift — no un refactor mecánico. Ver §6.

### Ola 3 — Entidad-céntrico + hubs (medio)
- **B2** NodeReception/ScanEvent → ficha · **B4** Promise → pestaña en Order · **B3** capacity → DispatchCalendar.
- **B6** CarrierHub (+ Tarifas/Liquidaciones adentro) · **B7** SellerHub (+ SellerSettlement adentro) ·
  **B9** Return hub · **B10** Helper hub.
- **B11** directorio "Socios comerciales".

### Ola 4 — Cockpits unificados (medio-alto)
- **B12** cockpit de liquidaciones (carrier+seller) · **B13** cockpit geográfico con capas · **B8** ProductHub.

### Ola 5 — Consolidación de dominio (medio-alto, por etapas con migración) — ✅ HECHA (2026-11-06)
- **A5** `OwnerDocuments` compartido + migrar Helper a `fleet_documents`. → VO `PersonalProfile` compartido;
  backfill aditivo de los documentos inline de Helper a `fleet_documents` + alertas leen una sola fuente
  (sin doble conteo) + `RegisterHelper`/`HelperView` repuntados. El jsonb inline queda como respaldo (no
  destructivo). *Nota honesta:* Vehicle usa el mismo patrón inline y NO se migró (consistencia futura).
- **A6** `PersonalProfile` VO compartido (Driver/Helper) + agregado `CrewMember`. → Hecho de forma ADITIVA
  sobre las tablas existentes (ids preservados, cero esquema nuevo): agregado `CrewMember(role)` con
  invariante de rol explícita (chofer⇒licencia; ayudante⇒sin licencia), repositorio que delega en Driver/Helper,
  read model + query `ListCrewMembers`, y alta unificada `RegisterCrewMember` que delega por rol. Driver/Helper
  se conservan. No se tocó la UI (ya unificada y escalable desde la Fase 1 del CRM).

### Ola 6 — Escala profunda / estratégico — ✅ HECHA (2026-11-06, por decisión del dueño pese a volumen aún bajo)
- **C10** ✅ proyección de reporting por outbox: mecanismo + corte `reporting_shipments` (projector enganchado al
  dispatcher del outbox + backfill + reader; `OperationsBrief` lee de la proyección con fallback). Camino de
  extensión documentado. *Pieza clave para extraer microservicios.*
- **C11** ✅ Customer canónico: proyección `customer_profiles` (id = documento CI/NIT normalizado) en `app/Customers/`,
  backfill desde shipments + comando + enlace aditivo en CustomerHub. Honesto: órdenes sin documento no se enlazan.
- **C5** ✅ keyset (cursor) en el listado de envíos (se conserva la rama offset por contrato) · **C6** ✅ partición
  de `telemetry_pings` preservando datos + BRIN ya existente (scan_events NO se particiona: rompería su idempotencia)
  · **C7** ✅ retención `prune-scan-events` (dry-run por defecto) · **C8** ✅ broadcast `fleet.node.{id}` · **C9** ✅
  `outbox:pump-daemon` multi-worker.

> Nota: a los volúmenes actuales (≈150 pings / 520 escaneos / 295 envíos) buena parte de la Ola 6 es infraestructura
> adelantada; se construyó aditiva y verificada, lista para cuando el volumen la justifique.

---

## 5. Matriz de priorización

| Valor ↓ \ Esfuerzo → | Bajo | Medio | Alto |
|----------------------|------|-------|------|
| **Alto** | C1, C2, C4, B1, B5, B12-rename, B14 | C3, A1, A2, B6, B7, B11, B13 | C10, C11 |
| **Medio** | A3, A4, B15, C9 | B2, B3, B4, B9, B10, B8, A5, C5, C8 | A6, C6 |
| **Bajo** | — | B12-cockpit, C7 | — |

**Dónde empezar:** la esquina **Alto-valor / Bajo-esfuerzo** (Ola 1) da el mayor retorno con el menor
riesgo: índices de `commerce_orders`, trigram para la búsqueda, y dos o tres movimientos entidad-céntrico.

---

## 6. Falsos positivos (NO hacer — documentado)

- **"Productos" vs "Catálogo de productos"**: una sola `ProductResource` cuyo label es "Catálogo de
  productos". No hay duplicado. *Dejar como está.*
- **`carrier_settlements` vs `seller_settlements`**: **dominios distintos** (pago a transportistas por
  corredor vs comisión/retención a sellers del marketplace; direcciones de dinero y ciclos de vida
  distintos). No fusionar agregados; sí comparten VOs (Money, DateRange) y se pueden presentar juntos (B12).
- **`coverage_zones` vs `coverage_geo_areas`**: relacionados pero distintos (zona operativa que cuelga de
  una jerarquía geográfica departamento→provincia→municipio). No son el mismo dato.
- **Los tres mapas**: solo `CoverageMap` ↔ `ZoneResource` se solapan de verdad (editor vs tabla del mismo
  dato); `NodeMap` plotea otra cosa. El cockpit geográfico (B13) los une por UX, no porque sean duplicados.
- **`DocumentResource` (fleet)**: cruza 3 entidades (vehículo/chofer/ayudante) y sirve de vista de
  compliance/vencimientos global → defendible como **directorio de documentos**, no "ítem suelto". Renombrar
  a "Documentos de flota" y usarlo como cockpit de vencimientos.
- **Consolidar los VOs duplicados (Money/GeoPoint/etc.) en un shared kernel (Ola 2)**: **reconsiderado como
  NO-GO** tras leer el código. Están deliberadamente separados por contexto (aislamiento DDD documentado en los
  docblocks, coherente con microservicios autónomos), han derivado en comportamiento (propiedades, `equals`,
  excepciones), y el "`float` de shipments" es un precio de presentación intencional, no un bug. DRY no justifica
  romper el aislamiento. Ver §4 Ola 2. (Si algún día se decide un shared kernel, es un cambio de arquitectura con
  ADR propio, no un refactor mecánico.)
- **Customer snapshot (execution, ADR 021)**: la identidad congelada del cliente es **deliberada**
  (anti-fraude, hecho histórico). No "deduplicar" contra el maestro; el canónico (C11) es un ReadModel que
  convive, no un reemplazo del snapshot.

---

## 7. Riesgos y forma de ejecutar

- **Migraciones**: todas aditivas e idempotentes (`if (!Schema::hasColumn/hasTable)`), con backup antes de
  cualquier cambio de datos (reglas de memoria: nunca `migrate:fresh`/`wipe`; cuidado con PgBouncer tras
  ALTER en vivo → reiniciar pgbouncer).
- **Índices grandes**: crear `CONCURRENTLY` en producción para no bloquear la tabla.
- **VOs compartidos (Ola 2)**: refactor puro de tipos → cubierto por PHPStan + tests; el riesgo real es solo
  el `float`→cents de shipments (revisar persistencia y vectores de prueba).
- **C10/C11 (estratégico)**: son proyectos en sí mismos; conviene un ADR dedicado antes de ejecutarlos, y
  hacerlos cuando el plan de microservicios o el volumen lo justifiquen (no antes de tiempo — ADR 001).
- **Cada ola termina verde**: pest + PHPStan 8 + Pint + validación en navegador, con números reportados.
- **El dueño commitea/pushea**: ningún commit automático.

---

## 8. Apéndice — archivos clave citados

- Cockpit read-services: `app/Support/Cockpit/{CustomerHub,GlobalSearch,AttentionQueues,EntityTimeline,NodeHub,ShipmentHub,DispatchTimeline,OperationsBrief}.php`, `app/Filament/Pages/PersonnelDirectory.php`.
- `commerce/orders/database/migrations/2026_10_29_000300_create_commerce_orders_table.php`.
- `shipments/…/Infrastructure/ReadModel/DbShipmentListReader.php`.
- Outbox (referencia de cómo hacerlo bien): `outbox/…/EloquentOutboxStore.php`, `…/ProcessOutboxMessage.php`.
- Búsqueda keyset+FTS (template): `commerce/search/…/DatabaseProductSearchReader.php`.
- VOs duplicados: `*/src/Domain/ValueObject/{Money,GeoPoint,TimeWindow}.php` en los contextos listados en §1.
- Tiempo real: `routes/channels.php`, `telemetry/…/BroadcastingLivePositionBroadcaster.php`.
- Telemetría/escaneos (volumen): `telemetry/…_create_telemetry_pings_table.php`, `execution/…_create_execution_scan_events_table.php`.
