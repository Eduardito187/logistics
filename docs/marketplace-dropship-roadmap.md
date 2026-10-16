# Roadmap — Marketplace / Dropship / Fulfillment por sellers (Dismac Logistics)

> Estado: PROPUESTA (2026-10-16). Documento de diseño y plan de construcción. No hay código aún; todo lo
> descrito es aditivo sobre la plataforma actual. Pensado para construirse con la skill de proyecto
> `.claude/skills/bounded-context` (DDD + CQRS + Clean Architecture, UUID v7, outbox) y en olas de agentes.

## 1. Problema y oportunidad

Hoy toda la plataforma asume producto **retail**: Dismac es el dueño del inventario (`OWNED`). No existe
ninguna noción de **seller/proveedor**, **propiedad del inventario**, **consignación** ni **recepción de
proveedor (inbound/ASN)**. Verificado en el dominio: 0 conceptos de `seller/marketplace/dropship/
consignment/inbound/owner` en `packages/logistics/*/src/Domain`.

El negocio necesita operar **productos que no son de Dismac**:

- **Marketplace / FBD (Fulfilled by Dismac):** el seller lleva su producto a un **almacén central** de
  Dismac; Dismac lo recibe, lo **etiqueta**, lo almacena y lo **distribuye** (a tienda, a otra ciudad, a
  otro almacén) exactamente igual que un producto propio.
- **Dropship:** el seller despacha directamente; Dismac coordina y/o etiqueta, o solo da visibilidad.

### Idea rectora (punto de convergencia)

Una vez que una unidad física está **recibida y etiquetada** en un almacén central, es
**operacionalmente idéntica** a un producto Dismac. Todo lo de **aguas abajo** (planificar, rutear,
última milla, telemetría, transferencias entre nodos) **ya existe y se reutiliza sin cambios**. Lo nuevo
está **aguas arriba** (entrada del seller + propiedad) y en **unas pocas etiquetas transversales** para
liquidación, devoluciones y SAP.

```
 SELLER                      ┌─────────── PUNTO DE CONVERGENCIA ───────────┐
   │  ASN / cita             │                                             │
   ▼                         ▼                                             ▼
[inbound] ──recepción+QC──> [labels] ──unidad etiquetada──> [planning/execution/telemetry/última milla]
   │  (cupos de andén            │  (QR Dismac)                  (REUTILIZADO TAL CUAL)
   │   = capacity espejo)        │
   ▼                            ▼
[sellers]                    [inventory]  ── atribución de dueño (consignación) ──> [sap-connector]
 (party, contrato)            (stock por dueño)                                      [settlement sellers]
```

### Principios de diseño (no negociables)

1. **Aditivo:** todo lo existente queda `OWNED` por defecto; ningún flujo retail se rompe.
2. **Clean Architecture por contexto** (`Presentation → Application → Domain ← Infrastructure`), Domain PHP puro.
3. **CQRS:** escritura por `Command`+`CommandHandler`, lectura por `Query`+`QueryHandler` con ReadModels.
4. **UUID v7** para toda identidad; **outbox transaccional** para todo I/O externo (SAP, seller, email).
5. **Cada contexto autónomo y extraíble a microservicio**; `inbound` es el primer candidato a extraer por volumen.
6. **Comentarios y docs en español; texto visible del admin en inglés** (`__()` + `lang/es.json`).
7. **Registro de package en 6 lugares** (ver §9) en el mismo cambio.

---

## 2. Modelo transversal: la dimensión de propiedad / fulfillment

Value object nuevo que viaja con la unidad y el envío. Vive en `shared` (lo usan varios contextos) o se
replica por contexto si se prefiere acoplamiento cero.

- **`FulfillmentMode`** (enum): `OWNED` (retail, hoy), `FBD` (marketplace fulfilled by Dismac),
  `DROPSHIP` (seller despacha).
- **`Ownership`** (VO `final readonly`): `ownerPartyId` (null = Dismac), `fulfillmentMode`,
  `sellerSkuRef` (opcional, el SKU del seller). Inmutable, named constructors (`dismacOwned()`,
  `sellerFbd(SellerId, sku)`, `sellerDropship(SellerId, sku)`).
- Regla de invariante: `OWNED` ⇒ `ownerPartyId === null`; `FBD/DROPSHIP` ⇒ `ownerPartyId !== null`.

Impacto: campo aditivo `ownership_*` (owner_party_id nullable + fulfillment_mode) en
`shipments` y `physical_units`; default `OWNED` en migración (back-fill trivial).

---

## 3. Contextos: nuevos y tocados

| Contexto | Tipo | Rol en el flujo marketplace |
|---|---|---|
| `sellers` | **NUEVO** | El seller como party: alta, contrato, condiciones de liquidación, catálogo de SKUs, SLAs. |
| `inbound` | **NUEVO** | ASN/cita, cupos de andén, goods receipt + QC + put-away → emite unidades etiquetadas. |
| `inventory` | **NUEVO** | Stock con atribución de dueño (consignación vs. propio), lotes, segregación lógica. |
| `settlement` (sellers) | **NUEVO** | Liquidación al seller por despacho, menos fees (almacenaje/fulfillment/última milla). |
| `shipments` | tocado (aditivo) | VO `ownership` en envío y unidad física. |
| `network` | tocado (aditivo) | Tipo de nodo "almacén central" + andenes de recepción. |
| `capacity` | tocado (aditivo) | Espejo de recepción: cupos de andén por día/almacén (hoy solo despacho). |
| `labels` | sin cambios | Punto de convergencia (QR Dismac). |
| `sap-connector` | tocado | Dimensión de propiedad: consignación ≠ material propio; mapeo por-seller. |
| `promise` | tocado | Lead time de inbound en la fecha prometida (dropship/FBD sin stock). |
| `planning` / `execution` / `telemetry` / última milla | sin cambios | Solo arrastran el tag de propiedad para reportería. |

---

## 4. FASE 1 — Fundación: propiedad + contexto `sellers`

**Objetivo:** introducir la noción de dueño sin romper nada. Al terminar, todo lo existente sigue siendo
`OWNED` y existe el seller como entidad, listo para colgarle inventario.

**Punto por punto:**

1. **VO de propiedad** (`shared` o replicado): `FulfillmentMode` enum + `Ownership` VO con named
   constructors e invariantes (§2). Tests unitarios del VO (sin Laravel).
2. **Contexto `sellers` — Domain:**
   - Agregado `Seller extends AggregateRoot`: `SellerId` (UUID v7), nombre legal, `tax_id` (NIT),
     estado (`Onboarding|Active|Suspended`), datos de contacto.
   - VO `SettlementTerms` (comisión %, fees fijos, ciclo de pago), `SellerSku` (sku del seller ↔ opcional
     material SAP), `ContactInfo`.
   - Eventos: `SellerRegistered`, `SellerActivated`, `SellerSuspended`, `SettlementTermsChanged`.
   - Contrato `SellerRepository`.
3. **Contexto `sellers` — Application (CQRS):**
   - Commands: `RegisterSeller`, `ActivateSeller`, `SuspendSeller`, `SetSettlementTerms`,
     `UpsertSellerSku`. Un handler por caso (void), despachan eventos al outbox.
   - Queries + ReadModels: `GetSeller`, `ListSellers` (con filtro por estado), `ListSellerSkus`.
4. **Contexto `sellers` — Infrastructure:** `SellerRecord` Eloquent (uuid pk string), mapper dominio↔fila,
   `EloquentSellerRepository`, migración (`sellers`, `seller_skus`, `seller_settlement_terms`),
   `SellersServiceProvider` (bind repos + registra handlers en `HandlerRegistry` + `loadMigrationsFrom`).
5. **Campo `ownership` aditivo** en `shipments` y `physical_units`: migración que añade
   `owner_party_id` (uuid null) + `fulfillment_mode` (string, default `'owned'`), back-fill a `owned`.
   Mapear en los Records/mappers correspondientes y exponerlo en el agregado como VO.
6. **Presentación — Admin Filament:** `SellerResource` (grupo **Commerce**, buscable, sin UUID visible),
   acciones transaccionales (activar/suspender/editar términos) vía `CommandBus`. Textos en inglés.
7. **API:** endpoints `POST /v1/sellers`, `GET /v1/sellers`, `GET /v1/sellers/{id}`, `PUT .../settlement-terms`;
   actualizar `openapi/v1.yaml` en el mismo cambio. Puerta `integrations` (service.auth) para el alta por API.
8. **Registro del package** en los 6 lugares (§9).
9. **DoD Fase 1:** Pest verde (unit del VO + feature del contexto) + PHPStan 8 + Pint + render del
   `SellerResource` 200 + seeders idempotentes de 2–3 sellers demo. Todo lo retail intacto (`OWNED`).

**Dependencias:** ninguna (es la base). **Riesgo:** bajo (aditivo).

---

## 5. FASE 2 — Inbound: ASN, cupos de andén y recepción (el caso FBD de punta a punta)

**Objetivo:** que un seller pueda anunciar y dejar producto en un almacén central, y que Dismac lo reciba,
inspeccione y **etiquete** — a partir de ahí la distribución ya funciona con lo existente.

**Punto por punto:**

1. **`network` (aditivo):** tipo de nodo `CentralWarehouse` + concepto de **andén de recepción**
   (receiving dock) con capacidad. Migración aditiva (`node_type`, `receiving_docks`).
2. **`capacity` (aditivo) — espejo de recepción:** hoy modela cupos de **despacho**; se añade el eje de
   **recepción** (cupos de andén por día/almacén/franja). Reutiliza el motor de cupos
   (`DefineCapacity/ConsumeCapacity/ReleaseCapacity`) con un `BucketScope` de tipo `inbound`.
3. **Contexto `inbound` — Domain:**
   - Agregado `Asn extends AggregateRoot` (Advanced Shipping Notice / cita): `AsnId`, `SellerId`, almacén
     destino, líneas (`AsnLine`: sku del seller, cantidad esperada), ventana de cita, estado
     (`Announced|Scheduled|Arrived|Receiving|Received|Closed|Cancelled`).
   - Agregado `GoodsReceipt`: contra un ASN, lo realmente recibido por línea (cantidad OK, rechazada,
     motivo QC), referencia a las unidades físicas emitidas.
   - VOs: `AppointmentWindow`, `ReceivedQuantity`, `QcOutcome` (`Accepted|Rejected|PartialDamage`).
   - Eventos: `AsnAnnounced`, `AsnScheduled`, `ShipmentArrived`, `GoodsReceived`, `UnitsLabeled`,
     `AsnClosed`, `AsnCancelled`.
   - Contratos: `AsnRepository`, `GoodsReceiptRepository`.
4. **`inbound` — Application (CQRS):**
   - Commands: `AnnounceAsn`, `ScheduleAsn` (consume cupo de andén en `capacity`), `RecordArrival`,
     `RecordGoodsReceipt` (por línea, con QC), `LabelReceivedUnits` (emite unidades etiquetadas),
     `CloseAsn`, `CancelAsn`.
   - Queries + ReadModels: `GetAsn`, `ListAsns` (por seller/almacén/estado/fecha), `GetReceivingDockCalendar`
     (reutiliza la lectura de cupos), `ListPendingArrivals`, `GetGoodsReceipt`.
5. **Convergencia con `labels`:** `LabelReceivedUnits` crea los `PhysicalUnit` (contexto `shipments`) con
   `ownership = sellerFbd(...)` y dispara la emisión de etiqueta QR (contexto `labels`, sin cambios). A
   partir de aquí la unidad es indistinguible operativamente de una Dismac.
6. **Convergencia con `execution`:** la recepción física reutiliza el escaneo de `execution`
   (`RecordNodeReception`/scan). El goods receipt puede proyectarse como eventos de ejecución.
7. **Infrastructure:** Records+mappers+repos de `inbound`; migraciones (`inbound_asns`, `inbound_asn_lines`,
   `inbound_goods_receipts`, `inbound_receipt_lines`); `InboundServiceProvider`.
8. **Presentación — Admin Filament (grupo Operations):**
   - Cockpit **"Inbound board"**: ASNs del día por estado (anunciadas, agendadas, por llegar, recibiendo),
     con acciones inline (agendar, registrar llegada, iniciar recepción) vía `CommandBus`. Mismo patrón
     operaciones-primero que `LastMileBoard`.
   - `AsnResource` + `ReceivingCalendar` (cupos de andén, espejo del `DispatchCalendar`).
9. **API:** `POST /v1/inbound/asn`, `PUT /v1/inbound/asn/{id}/schedule`, `POST .../arrival`,
   `POST .../receipt`, `POST .../label`; `GET /v1/inbound/receiving-calendar`. Puerta `integrations`
   para que el seller anuncie por API. Actualizar `openapi/v1.yaml`.
10. **Outbox / notificaciones:** eventos del seller (cita confirmada, recepción cerrada con discrepancias)
    salen por outbox → canal del seller (email/API webhook), nunca I/O síncrono en el request.
11. **DoD Fase 2:** Pest verde (recepción FBD de punta a punta: ASN → cupo → recepción → etiqueta →
    unidad distribuible) + PHPStan 8 + Pint + render de los cockpits 200 + seeders demo de un ASN
    recibido. **Hito de negocio: FBD operativo reutilizando toda la distribución existente.**

**Dependencias:** Fase 1 (sellers + ownership). **Riesgo:** medio (nuevo contexto + 2 contextos tocados).

---

## 6. FASE 3 — Inventario con dueño + SAP consignación

**Objetivo:** que el stock sepa de quién es cada lote (aunque esté físicamente mezclado) y que SAP no
trate el stock de sellers como material propio de Dismac.

**Punto por punto:**

1. **Contexto `inventory` — Domain:**
   - Agregado `StockLot`: `LotId`, almacén, sku, `Ownership` (propio/consignación de un seller), cantidad
     disponible/reservada, costo/valor, fecha de ingreso (trazable al goods receipt).
   - Operaciones de dominio: `receive`, `reserve`, `release`, `pick`, `adjust`, `transfer` (entre nodos).
   - **Segregación lógica, no física:** el stock se puede comingle físicamente; la propiedad vive en el lote.
   - Eventos: `StockReceived`, `StockReserved`, `StockPicked`, `StockTransferred`, `StockAdjusted`.
2. **`inventory` — Application:** Commands (`ReceiveStock`, `ReserveStock`, `PickStock`, `TransferStock`,
   `AdjustStock`) + Queries (`GetStockOnHand` por almacén/sku/dueño, `ListLots`, `GetAvailableToPromise`).
3. **Enganche con Fase 2:** `GoodsReceived` → `ReceiveStock` (crea el lote con el dueño correcto). El
   despacho/última milla descuenta del lote correcto (FIFO por dueño o política configurable).
4. **`sap-connector` (tocado) — dimensión de propiedad:**
   - El **mapeo de material** (`MaterialMappingResource`) pasa a ser **por seller** (material + centro +
     almacén + seller), o se marca el stock como **consignación** para no postear traspaso de propiedad.
   - Los **traspasos** (`StockTransferResource`) distinguen `OWNED` (postea a SAP como hoy) vs.
     `CONSIGNMENT` (movimiento logístico sin cambio de titularidad en SAP, o posteo a un almacén de
     consignación según la política contable). Ver [docs/adr/002] (frontera con SAP) y la sección "Traspasos/
     Mapeos de material SAP" del admin.
   - Regla dura: **nunca** postear stock de seller como material propio de Dismac en SAP.
5. **Infrastructure + migraciones** (`inventory_lots`, `inventory_movements`) + `InventoryServiceProvider`.
   Índices pensados para millones de lotes (por almacén+sku+dueño; UUID v7 ordenable ayuda).
6. **Presentación:** `StockOnHandResource`/cockpit de inventario por almacén con filtro por dueño
   (propio/seller), sin UUID visible. Ajuste del admin del sap-connector para la dimensión de propiedad.
7. **DoD Fase 3:** Pest verde (recepción→lote→reserva→pick con dueño correcto; SAP no recibe consignación
   como propio) + PHPStan 8 + Pint + render 200. Available-to-Promise por dueño disponible para `promise`.

**Dependencias:** Fases 1–2. **Riesgo:** medio-alto (toca SAP y contabilidad de inventario).

---

## 7. FASE 4 — Liquidación de sellers + logística inversa (devoluciones)

**Objetivo:** cerrar el ciclo comercial (pagar al seller lo que corresponde) y físico (devolver lo que no
se vende o lo que el cliente retorna).

**Punto por punto:**

1. **Contexto `settlement` (sellers) — Domain:** se **clona el patrón `carriers/Settlement`**.
   - Agregado `SellerSettlement`: periodo, líneas (despachos del seller en el periodo), fees aplicados
     (comisión, almacenaje, fulfillment, última milla), neto a pagar, estado (`Draft|Confirmed|Paid`).
   - VO `Money` por contexto (replica `carriers/Money`, centavos + currency, default BOB).
   - Eventos: `SettlementDrafted`, `SettlementConfirmed`, `SettlementPaid`.
2. **`settlement` — Application:** `DraftSellerSettlement` (agrega despachos del periodo), `ConfirmSettlement`,
   `MarkSettlementPaid`; Queries `GetSettlement`, `ListSettlements`, `GetSellerStatement`.
3. **Fuente de datos:** lee (cross-context, read-only, en lote) los despachos marcados con `ownership` del
   seller desde `execution`/`shipments`, y los fees desde `sellers.SettlementTerms` + inventario (almacenaje).
4. **Logística inversa (devoluciones):** nuevo flujo en `inbound`/`execution`:
   - Devolución **de cliente** de producto de seller → retorna a almacén central → decisión: re-stock
     (vuelve a lote del seller) o devolución al seller.
   - Devolución **al seller** (RTV, return-to-vendor): ASN inverso; el seller retira o Dismac despacha.
   - Agregado `ReturnOrder` con estados; eventos `ReturnInitiated`, `ReturnReceived`, `ReturnDispositioned`.
5. **Presentación:** `SellerSettlementResource` (grupo Commerce/Administration), cockpit de devoluciones.
6. **Outbox:** estados de liquidación y devolución notifican al seller por su canal.
7. **DoD Fase 4:** Pest verde (liquidación de un periodo con fees correctos; devolución re-stock y RTV) +
   PHPStan 8 + Pint + render 200.

**Dependencias:** Fases 1–3. **Riesgo:** medio (financiero; requiere reglas de negocio claras de fees).

---

## 8. FASE 5 — Dropship puro + portal/API del seller

**Objetivo:** soportar el modo donde el seller despacha directamente, y darle al seller visibilidad y
autoservicio.

**Punto por punto:**

1. **`promise` (tocado):** la fecha prometida incorpora el **lead time** del seller (dropship) o del
   inbound (FBD sin stock disponible). Regla en el motor de promesa, por seller/sku.
2. **Flujo dropship en `execution`:** el seller confirma despacho; Dismac puede (a) solo trackear, (b)
   etiquetar y hacer la última milla, o (c) coordinar transportista tercero (`carriers`). El tag
   `fulfillment_mode = DROPSHIP` rutea el flujo.
3. **Portal / API del seller (puerta `integrations`):**
   - Autoservicio: crear ASN, ver citas, ver recepciones y discrepancias, ver stock on-hand propio, ver
     liquidaciones, iniciar devoluciones. Todo por API tokeni­zada con permisos seleccionables (reusa el
     contexto `access`: tokens de integración con permisos).
   - Webhooks de eventos del seller (vía outbox): `goods_received`, `settlement_confirmed`, etc.
4. **Observabilidad del seller:** ReadModels optimizados y cacheados (forma primitiva, nunca objeto de
   dominio) para el portal, separados del lado de escritura (CQRS puro).
5. **DoD Fase 5:** Pest verde (promesa con lead time; dropship de punta a punta; API del seller con
   permisos) + PHPStan 8 + Pint + render 200 + contrato OpenAPI del portal del seller completo.

**Dependencias:** Fases 1–4. **Riesgo:** medio.

---

## 9. Checklist de registro de cada package nuevo (6 lugares, mismo cambio)

Por cada contexto nuevo (`sellers`, `inbound`, `inventory`, `settlement`):

1. `composer.json` raíz: `require` del package + `extra.laravel.dont-discover`.
2. `bootstrap/providers.php`: alta del ServiceProvider (orden: `SharedServiceProvider` primero).
3. `AdminPanelProvider`: `discoverResources(in: .../<ctx>/src/Presentation/Filament)` + grupo de navegación
   + registrar Pages/cockpits en `->pages([...])`.
4. `routes/api.php`: fragmento de rutas del contexto, en la puerta correcta (`app`/`channels`/`integrations`).
5. `DatabaseSeeder`: seeder idempotente del contexto (UUID v7 estables; sin `create` ciego).
6. `openapi/v1.yaml`: endpoints nuevos, en el mismo cambio que el endpoint.

---

## 10. Notas de escala (el cielo es el límite, con cimientos)

- **Volumen:** millones de SKUs de miles de sellers; UUID v7 (índices compactos, ordenable en el tiempo),
  outbox idempotente por `event_id` con `SELECT ... FOR UPDATE SKIP LOCKED` + lease (workers en paralelo).
- **Inventario commingled:** segregación **lógica** por lote/dueño, no física; evita duplicar almacenamiento.
- **Extracción a microservicio:** `inbound` es el primer candidato (picos de recepción independientes);
  extraerlo = mover su package y poner outbox/CQRS sobre transporte de red, sin reescribir (ADR 001/008).
- **Lecturas:** ReadModels por lado de consulta, cacheados en forma primitiva; el portal del seller no
  toca el lado de escritura.
- **SAP:** la frontera no cambia (ADR 002); se añade solo la **dimensión de propiedad** para no mezclar
  titularidad contable.

---

## 11. Resumen ejecutivo

| Fase | Entrega | Hito de negocio | Depende de |
|---|---|---|---|
| 1 | Propiedad (VO) + contexto `sellers` | El seller existe; todo lo retail intacto | — |
| 2 | `inbound` (ASN + cupos de andén + recepción + etiquetado) | **FBD operativo** reutilizando distribución | 1 |
| 3 | `inventory` con dueño + SAP consignación | Stock atribuido; SAP no mezcla titularidad | 1, 2 |
| 4 | `settlement` de sellers + logística inversa | Se le paga al seller; devoluciones | 1–3 |
| 5 | Dropship puro + portal/API del seller | Autoservicio y dropship de punta a punta | 1–4 |

El **70% del valor** (recibir y distribuir producto de sellers) se entrega en las **Fases 1–2**, porque
reutiliza toda la plataforma de distribución ya construida. El trabajo nuevo real son **2–3 contextos**
(`sellers`, `inbound`, `inventory`) más ajustes aditivos.
