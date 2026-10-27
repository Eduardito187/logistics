# Roadmap — Plataforma logística para e-commerce B2B · B2C · C2C (mayorista + minorista)

> Origen: recomendaciones del área de **POS** y **MPS** (reunión 2026-10). Este documento aterriza cada
> recomendación contra lo que la plataforma YA tiene, detecta los huecos y propone el roadmap completo
> para operar los tres modelos de negocio (mayorista B2B, minorista B2C y marketplace C2C).
>
> Análisis y diseño: **no** levanta Docker ni ejecuta nada. Toda la propuesta respeta la arquitectura
> del repo (DDD + CQRS + Clean, outbox, UUID v7, contextos autónomos — ADR 001/008) y las reglas de
> CLAUDE.md. Principio rector heredado del marketplace: **una vez recibida y etiquetada una unidad, es
> operacionalmente idéntica aguas abajo** → planning/execution/telemetry/tracking se REUTILIZAN; lo
> nuevo casi siempre es aguas arriba (canal, propiedad, unidad de manejo) y restricciones.

---

## 1. Lo que dijo POS/MPS, traducido y contrastado

Las notas de la reunión eran palabras sueltas. Acá va cada una: qué significa para la plataforma, qué
ya existe y qué falta.

### 1.1 "Mayorista, retail, almacén y e-commerce" — los segmentos a cubrir

| Término | Qué pide | Estado actual | Hueco |
|---|---|---|---|
| **Retail / minorista (B2C)** | Entregar pedidos de consumidor CD/tienda→cliente | **Cubierto** (shipments, planning, execution, tracking, promise, capacity) | Segmentar explícitamente el canal (hoy es implícito) |
| **Mayorista (B2B)** | Entregar pedidos voluminosos a clientes-empresa: pallets, citas de descarga, remito/POD formal, ventanas estrictas, entregas programadas | **Parcial**: la red ya contempla corredores por volumen (tráiler/camión/tercero) pero NO hay unidad de manejo (pallet), citas en destino, ni POD B2B | **Hueco grande → Fase M** |
| **Almacén (WMS)** | Operación de almacén: recepción, ubicaciones, putaway, picking, packing, conteo | **Parcial**: `inbound` (ASN/recepción/QC/etiquetado) + `inventory` (lotes, ATP, ledger, segregación por dueño). Falta ubicaciones/bins, estrategias de picking/putaway, packing, slotting, conteo cíclico | **Hueco → Fase W** |
| **E-commerce** | Ingesta de pedidos online (tienda web propia + Magento + marketplace) | **Parcial**: entran pedidos (SAP, Magento, tiendas, Excel); marketplace vía `sellers`. Falta la capa de adaptadores de canal unificada | **Hueco → Fase I** |

### 1.2 "Planificación, control de flota, seguimiento, control, tracking, flota, almacenes" — capacidades

Casi todo **existe**; lo que falta es profundidad y que respete los nuevos segmentos.

| Capacidad | Dónde vive hoy | Estado |
|---|---|---|
| **Planificación** | `planning` (VRP Clarke-Wright + 2-opt sobre OSRM, auto-planner 2 modos, geometría server-side) | ✅ sólido; falta **load/capacity planning** mayorista y restricciones por tipo de vehículo (R-04) |
| **Control de flota** | `fleet` (vehículos, choferes, docs, alertas de vencimiento) + `fleetops` (combustible, mantenimiento, TCO, costo/km) | ✅ sólido; falta **taxonomía de vehículos** (trailer/rígido/van/moto/tercero) y capacidades |
| **Seguimiento / tracking** | `tracking` (por unidad, GPS vivo, página pública, web push) + `telemetry` (GPS en vivo, trips, geocercas, alertas, Reverb) | ✅ sólido |
| **Control (torre de control)** | Admin Filament (Control Tower como landing, cockpits por frente, 5 grupos) | ✅ sólido |
| **Almacenes** | `network` (nodos: CD, tienda, punto de recojo, almacén central) + `inbound` + `inventory` | ⚠️ ver Fase W (WMS) |

### 1.3 "Entregas: camiones, trailers, transportadoras y terceros" — modos de entrega

La visión ya dice "entre departamentos: por volumen del corredor → tráiler, camión o transportista
tercero". Lo que falta es **modelarlo como datos** y que el ruteo/planificación lo respete.

| Modo | Estado | Hueco |
|---|---|---|
| **Camiones (flota propia)** | ✅ `fleet`/`fleetops` | Falta tipo y capacidad por vehículo |
| **Trailers / tráiler** | ❌ no hay tipo de vehículo | **Fase V**: `VehicleType` + capacidad (peso/volumen/pallets) |
| **Transportadoras** | ✅ `carriers` (+ `settlement`) | Falta tendering, rate-shopping, guía/label por carrier, webhooks de tracking |
| **Terceros / 3PL** | ✅ parcial (`carriers`, dropship `ThirdPartyCarrier`) | Idem carriers + SLAs y manifiestos |

### 1.4 "Lisa, Sap" — sistemas a integrar

- **SAP**: ✅ `sap-connector` (ACL evento→outbox, fuera del dominio SAP, ADR 002). TransferKind
  owned/consignment, material mappings con seller.
- **Lisa = LISA WMS** (confirmado): **WMS certificado para SAP Business One** (add-on de N'ware
  Technologies; también disponible para SAP Business ByDesign). Orientado a **empresas mayoristas y de
  distribución** — encaja de lleno con el objetivo B2B. Es un **WMS completo**: multi-almacén
  físico/virtual, zonas, ubicaciones aleatorias/fijas por SKU con capacidad y velocidad, secuencia de
  recorrido (pick path), estrategias de reabasto, hard allocation, **cross-docking**, picking por lote/
  granel, contenedores y **License Plate**, **putaway dirigido** al recibir OC, conteo físico y cíclico,
  RMA, **FIFO/FEFO**, serie/lote, RF móvil (web), 3PL remoto, y —ojo— **también** ruteo de vehículos,
  EDI "plug-and-play" e integración con carriers.

  **Implicación arquitectónica (grande):** LISA es el **sistema de registro del almacén** para el stock
  propio en SAP B1. Eso (a) **reemplaza gran parte de la "Fase W" que iba a construirse** (no reconstruir
  un WMS: integrarse con LISA), y (b) **solapa** con nuestros `inbound`/`inventory` e incluso con
  `planning`/`carriers`. Hay que trazar una **frontera de responsabilidad explícita** (ver §2.5) para que
  no haya doble dueño del dato. **El sitio de LISA NO documenta API/webhooks públicos**: el método real
  (DI-API / Service Layer de SAP B1 / base / EDI / archivos) se confirma con N'ware. Ver Fase I y
  decisión #1 (ahora sobre el *método*, no sobre *qué es*).

---

## 2. Estado actual de la plataforma (base para el gap analysis)

25 bounded contexts, DDD/CQRS/Clean, outbox transaccional, UUID v7. Agrupados por rol:

- **Red y cobertura**: `network` (nodos CD/tienda/punto-recojo/almacén-central; corredores), `coverage`
  (polígonos PostGIS, jerarquía geográfica), `settings`.
- **Pedido → promesa → plan → ejecución**: `shipments` (pedido + líneas + unidades + propiedad),
  `promise` (fecha/ventana prometida, lead times, ATP), `capacity` (cupos por municipio/dock),
  `planning` (VRP/rutas), `execution` (preparar→cargar→escanear→entregar→recibir, dropship), `labels`
  (QR firmado Ed25519).
- **Visibilidad**: `tracking` (por unidad + público), `telemetry` (GPS vivo, trips, geocercas).
- **Flota y terceros**: `fleet` (vehículos/choferes/docs), `fleetops` (costos/mantenimiento/TCO),
  `carriers` (transportadoras), `settlement` (liquidación).
- **Marketplace / propiedad no-Dismac**: `sellers`, `inbound` (ASN/recepción/QC/etiquetado), `inventory`
  (lotes/ATP/ledger/segregación), `returns` (logística inversa), `sellerportal` (solo lectura).
- **Plataforma**: `access` (RBAC + tokens), `notifications` (multicanal), `outbox`, `sap-connector`,
  `shared` (núcleo CQRS/VOs/ids).

**Cobertura de modelos de negocio hoy:**
- **B2C retail**: ✅ completo.
- **C2C / marketplace** (seller→comprador, FBD/dropship): ✅ fundaciones hechas (Fases 0–5 marketplace,
  ver `docs/marketplace-dropship-roadmap.md`). Falta "C2C puro" (vendedor persona: KYC, payout, primer
  tramo/pickup, confianza).
- **B2B mayorista**: ⚠️ el transporte físico sirve, pero faltan los conceptos mayoristas (pallet, cita,
  POD formal, consolidación de carga, entregas programadas).

---

## 2.5 Frontera: LISA WMS ↔ SAP Business One ↔ nuestra plataforma

Con LISA WMS en la foto hay tres sistemas que podrían pelearse el mismo dato. Regla: **un solo dueño por
concepto.** Propuesta (a validar con el negocio y N'ware):

| Concepto | Dueño (system of record) | Nuestra plataforma |
|---|---|---|
| Maestro de artículos / SKU, precios, clientes | **SAP B1** | Consume (read-only, vía `sap-connector`) |
| Stock propio, ubicaciones/bins, putaway, picking, packing, conteo, LP | **LISA WMS** | Consume estado "listo para despacho"; NO reconstruye el WMS |
| Orden "preparada/empacada/lista para enviar" | **LISA WMS** (emite el hecho) | **Ingesta** y arranca planning/execution |
| Stock de **marketplace** (seller FBD/segregado) y de **nodos fuera de LISA** (tiendas micro-fulfillment) | **Nuestra `inventory`** | Dueño (LISA no los gestiona) |
| **Planificación de transporte / ruteo / última milla** | **Nuestra `planning`** | Dueño (LISA solo hace ruteo básico; el nuestro es VRP + OSRM + multi-modal) |
| **GPS en vivo, tracking por unidad, página pública al cliente** | **Nuestra `telemetry`/`tracking`** | Dueño (LISA no lo hace) |
| Flota propia, costos, mantenimiento, TCO | **Nuestra `fleet`/`fleetops`** | Dueño |
| Transportadoras: tendering, SLA, liquidación, última milla | **Nuestra `carriers`/`settlement`** | Dueño (LISA tiene integración carrier básica; la nuestra es más profunda) |
| Citas de descarga en cliente B2B, POD formal, consolidación de carga | **Nuestra plataforma** (`capacity`/`execution`/`planning`) | Dueño |

**Handoff principal:** LISA (sobre SAP B1) prepara y deja la orden "lista para despacho" → **nuestra
plataforma toma desde ahí** (planifica, rutea, ejecuta última milla, da visibilidad) y **devuelve el
estado de entrega** (entregado/fallido/POD) a SAP B1 (y si hace falta, a LISA). Es el mismo principio del
marketplace: *al quedar lista/etiquetada, la unidad entra al flujo común aguas abajo*.

**Dónde SÍ construimos WMS propio:** solo para lo que LISA no cubre — recepción/etiquetado de
**marketplace** (`inbound`/`inventory` ya existentes) y **micro-fulfillment en tienda** (ship-from-store,
Fase P). Para los almacenes centrales con SAP B1, **LISA manda**.

---

## 3. Gap analysis — qué falta para B2B + B2C + C2C mayorista+minorista

Ordenado por impacto. Cada hueco se convierte en una fase (§4).

1. **Segmento y canal de venta ausentes.** `shipments` no etiqueta el pedido con su canal
   (ecommerce-B2C / mayorista-B2B / marketplace-C2C / POS-tienda) ni el tipo de cliente. Sin esto no se
   pueden diferenciar SLA, ruteo, POD ni consolidación. → **Fase S (segmentación), base de todo.**
2. **Logística mayorista (B2B).** Falta: unidad de manejo (pallet/caja/unidad), citas de descarga en el
   cliente, POD formal (remito/sello/firma, entrega parcial, rechazo), entregas programadas recurrentes,
   consolidación/armado de carga. → **Fase M.**
3. **WMS.** Para los almacenes centrales con SAP B1, **el WMS es LISA** (no se reconstruye): el hueco es
   **integrarse con LISA** y recibir el hecho "orden lista para despacho". `inventory`/`inbound` siguen
   siendo nuestros solo para marketplace y nodos fuera de LISA. → **Fase W (ahora = integración + WMS
   complementario).**
4. **Taxonomía de vehículos + restricciones multimodal.** No hay `VehicleType` ni capacidades; el ruteo
   no respeta restricciones por vehículo (R-04 quedó pendiente). → **Fase V.**
5. **Profundidad de transportadoras / 3PL.** Falta tendering, rate-shopping, guía/label por carrier,
   webhooks de tracking entrante, SLAs, manifiestos. → **Fase T.**
6. **Capa de canales / integraciones.** Un patrón uniforme de adaptadores de ingesta (e-commerce propio,
   Magento, POS, marketplace, EDI B2B, SAP, **Lisa**). → **Fase I.**
7. **C2C puro.** KYC de vendedor persona, payout, primer tramo (pickup al vendedor), devoluciones C2C,
   reputación. Mayoría es commerce; lo logístico (pickup + returns) sí es nuestro. → **Fase C.**
8. **Demanda y capacidad (ángulo MPS).** Pronóstico de demanda, planificación de capacidad/flota, load
   planning, S&OP logístico. Parte está en "Fase 3 Optimizar" de `docs/roadmap.md`. → **Fase D.**
9. **POS / tienda como nodo de fulfillment.** BOPIS (recojo en tienda ya existe), **ship-from-store**
   (tienda despacha con su inventario), devolución en tienda, click&collect con inventario por tienda. →
   **Fase P (POS).**

---

## 4. Roadmap por fases

Convenciones: cada ítem lleva **ID**, **contexto** (nuevo `★` o aditivo), **dependencia** y si es
**agent-doable** (construible por agentes de código) o **requiere negocio/infra**. Todo es **aditivo**:
no rompe el retail B2C vigente (default = ecommerce/owned). DoD de CLAUDE.md por paquete (Pest + PHPStan
8 + Pint + verificación real). Registrar contextos nuevos en los 6 lugares (bootstrap/providers,
composer dont-discover, openapi, es.json, subscribers, seeders).

### Fase S — Segmentación de canal y cliente (FUNDACIÓN, va primero)

Base transversal, barata, habilita todo lo demás. Mismo patrón que el VO `Ownership` del marketplace.

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| S-01 | VO `SalesChannel` (`EcommerceB2C`, `WholesaleB2B`, `MarketplaceC2C`, `PosStore`, `Manual`) en `shared` | `shared` ★VO | Enum `final`, como `FulfillmentMode` |
| S-02 | VO `CustomerType` (`Consumer`, `Business`, `Reseller`, `Internal`) en `shared` | `shared` ★VO | |
| S-03 | Campos aditivos `sales_channel` + `customer_type` en `shipments` (default `EcommerceB2C`/`Consumer`, retail intacto) | `shipments` aditivo | Migración aditiva idempotente |
| S-04 | Exponer canal/segmento en ReadModels y API (enriquecido, legible, sin UUID) | `shipments` aditivo | ADR enriquecimiento |
| S-05 | Filtros por canal/segmento en el admin (Control Tower y cockpits) + badges | Presentation | Texto en inglés, `->native(false)` |

> Dependencia: ninguna. **Agent-doable.** Habilita M, V, T, P para ramificar por canal.

### Fase M — Logística mayorista B2B

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| M-01 | **Unidad de manejo** `HandlingUnit` (pallet / caja master / unidad) con jerarquía y cantidades | `shipments` aditivo o ★`handling` | Pallet = N cajas = M unidades; impacta etiquetas y carga |
| M-02 | **Citas de descarga en destino** (dock appointment del cliente B2B): ventana, muelle, contacto | `capacity` aditivo (eje `delivery_appointment`) | Reusar `BucketScope` como el eje inbound |
| M-03 | **POD B2B formal**: remito firmado/sellado, entrega parcial, rechazo con motivo, devolución en el acto | `execution` aditivo | Reusa POD S3 + firma; nuevo: parcial/rechazo |
| M-04 | **Entregas programadas / recurrentes** (contratos de entrega: día fijo, frecuencia) | ★`contracts` o `promise` aditivo | Genera shipments por calendario |
| M-05 | **Consolidación y armado de carga** (load building): agrupar pedidos por cliente/corredor en un viaje, chequear capacidad del vehículo | `planning` aditivo | Depende de V (capacidad del vehículo) |
| M-06 | **Precio/condición de entrega** B2B (incoterm, quién paga el flete, zona tarifaria) — solo el dato logístico, no el pricing comercial | `shipments` aditivo | |

> Dependencia: S, V (para M-05). **Agent-doable** salvo M-06 (necesita definición del negocio).

### Fase W — WMS: integración con LISA + WMS complementario

Con LISA WMS como sistema de almacén para SAP B1, esta fase es **primero integración** (W-A) y **solo
después** construcción propia, acotada a lo que LISA no gestiona (W-B). No reconstruir el WMS de LISA.

**W-A · Integración con LISA WMS (prioritario)**

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| W-A1 | Definir el **método de integración con LISA/N'ware** (DI-API / Service Layer de SAP B1 / EDI / archivos / vía SAP B1 como hub) | diseño | **Decisión #1** — confirmar con N'ware |
| W-A2 | **Conector `lisa-connector`** (ACL + outbox, como `sap-connector`): nunca HTTP síncrono en request | ★`lisa-connector` | Traduce hechos LISA ↔ dominio |
| W-A3 | **Ingesta "orden lista para despacho"** desde LISA → crea/activa el shipment para planning/execution | `lisa-connector`→`shipments` | Handoff principal (§2.5) |
| W-A4 | **Devolver estado de entrega** (entregado/fallido/POD) a SAP B1/LISA | `lisa-connector` | Vía outbox |
| W-A5 | **Mapeo de ubicaciones/LP/lote** de LISA a nuestras unidades cuando haga falta trazabilidad fina | `lisa-connector` | License Plate ↔ unidad/bulto |
| W-A6 | **Reconciliación** (evitar doble dueño del stock; respetar §2.5) | `inventory`/`lisa-connector` | Stock propio = LISA/SAP, no duplicar |

**W-B · WMS propio complementario (solo nodos fuera de LISA: marketplace y tiendas)**

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| W-B1 | **Ubicaciones / bins** (zona, pasillo, rack, nivel) + capacidad | ★`warehouse` o `inventory` aditivo | Solo nodos sin LISA |
| W-B2 | **Putaway** dirigido (fijo / caótico / ABC) | `warehouse` | Tras `inbound.goods_received` (marketplace) |
| W-B3 | **Picking** por ola / lote / zona + ruta de picking | `warehouse` | Dirigido por scanner (app) |
| W-B4 | **Packing** (estación, verificación, consolidación de bultos) | `warehouse` + `labels` | Reusa etiquetas |
| W-B5 | **Conteo cíclico** y ajustes con ledger | `inventory` aditivo | Ya hay `inventory_movements` |
| W-B6 | **Reabastecimiento** + **slotting** ABC | `warehouse` | |
| W-B7 | Tareas de almacén dirigidas en la app (cola por operario) | `execution`/app | Contrato OpenAPI |

> Dependencia: S. W-A depende de confirmar el método con N'ware (negocio). W-B es **agent-doable** en
> backend; app Flutter + scanners son repo aparte/infra. **Regla:** para almacenes SAP B1, manda LISA;
> construir W-B solo donde LISA no opera.

### Fase V — Vehículos multimodal (camión, trailer, van, moto, tercero)

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| V-01 | VO/entidad `VehicleType` (rígido, **tráiler**, van, camioneta, moto, tercero) + capacidad (peso, volumen, **pallets**, bultos) | `fleet` aditivo | Habilita el término "trailers" de POS/MPS |
| V-02 | Restricciones por vehículo (zonas urbanas, horarios, tipo de carga, altura/peso por ruta) | `fleet`/`planning` | |
| V-03 | **Ruteo respeta tipo y capacidad** (R-04 pendiente): el VRP no asigna más de lo que entra ni manda un tráiler a una zona restringida | `planning` aditivo | Depende de V-01 |
| V-04 | Selección de modo por corredor (tráiler/camión/tercero según volumen) — automatiza la regla de la visión | `planning`/`network` | |

> Dependencia: ninguna para V-01/02; V-03/04 dependen de V-01. **Agent-doable.**

### Fase T — Transportadoras y 3PL (profundidad)

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| T-01 | **Tendering / licitación**: ofrecer un envío a transportadoras por regla/costo | `carriers` aditivo | |
| T-02 | **Rate-shopping** (comparar costo/SLA entre transportadoras) | `carriers` | |
| T-03 | **Guía / label por carrier** (formato del tercero) + manifiesto de entrega | `carriers`/`labels` | |
| T-04 | **Webhooks de tracking entrante** del carrier → estados en `tracking` (vía outbox/ACL) | ★`carrier-connector` o `carriers` aditivo | Nunca HTTP síncrono |
| T-05 | **SLA por transportadora** + alertas de incumplimiento | `carriers` + `notifications` | |

> Dependencia: S. **Agent-doable** el modelo; credenciales/endpoints reales de cada carrier = negocio/infra.

### Fase I — Canales e integraciones (e-commerce, POS, marketplace, SAP, **Lisa**, EDI)

Patrón uniforme: **cada sistema externo = un conector ACL con outbox** (como `sap-connector`).

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| I-01 | **Puerto de ingesta de pedidos** unificado (un `IngestOrder` command + mapeo por canal) | `shipments` aditivo | Normaliza SAP/Magento/POS/marketplace/Excel |
| I-02 | Conector **e-commerce propio / Magento** (dis-commerce como adaptador, ADR visión) | ★`commerce-connector` | |
| I-03 | Conector **POS / tiendas** (pedidos de tienda, BOPIS, ship-from-store) | ★`pos-connector` | Ver Fase P |
| I-04 | Conector **LISA WMS** (ACL + outbox) — ver **Fase W-A** (es el WMS sobre SAP B1) | ★`lisa-connector` | Método a confirmar con N'ware (decisión #1) |
| I-05 | **EDI B2B** (órdenes/ASN/facturas con clientes-empresa grandes) | ★`edi-connector` | Si el negocio lo pide |
| I-06 | Idempotencia y reconciliación de ingesta (evitar pedidos duplicados entre canales) | `shipments`/`outbox` | |

> Dependencia: S. **Agent-doable** el esqueleto; cada conector necesita el contrato/credenciales reales
> del sistema externo (negocio/infra). **I-04 bloqueado hasta aclarar "Lisa".**

### Fase C — C2C puro (vendedor persona)

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| C-01 | **Primer tramo / pickup al vendedor** (recoger en domicilio del seller) | `execution`/`planning` aditivo | Reusa ruteo; nuevo: parada de origen |
| C-02 | **Devoluciones C2C** (comprador→vendedor) | `returns` aditivo | Ya hay return-to-seller |
| C-03 | KYC / verificación de vendedor persona, payout, reputación | `sellers` aditivo (+ commerce) | Mayoría fuera de logística |

> Dependencia: S + fundaciones marketplace (hechas). **Agent-doable** C-01/C-02; C-03 es commerce.

### Fase D — Demanda y capacidad (ángulo MPS) / Optimizar

Alinea con "Fase 3 — Optimizar" de `docs/roadmap.md` (costos/pronóstico/simulador ya iniciada).

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| D-01 | **Pronóstico de demanda** por canal/zona/fecha (alimenta cupos y flota) | ★`forecasting` o analítica | |
| D-02 | **Planificación de capacidad** (slots y flota según pronóstico) | `capacity`/`planning` | |
| D-03 | **Load planning** (plan de carga previo al ruteo, mayorista) | `planning` | |
| D-04 | **S&OP logístico / simulador** (what-if de flota, costo, SLA) | analítica | Fase 3 roadmap |

> Dependencia: datos históricos reales (Discovery). **Parcial agent-doable**; necesita datos y negocio.

### Fase P — POS / tienda como nodo de fulfillment

| ID | Qué | Contexto | Notas |
|---|---|---|---|
| P-01 | **Ship-from-store** (tienda despacha con su inventario local) | `inventory`/`network` aditivo | Nodo tienda con stock |
| P-02 | **BOPIS / click&collect** con inventario por tienda (recojo ya existe; sumar reserva de stock) | `inventory`/`execution` | |
| P-03 | **Devolución en tienda** (omnicanal) | `returns` aditivo | |
| P-04 | Visibilidad de inventario por tienda para promesa omnicanal (ATP multi-nodo) | `inventory`/`promise` | |

> Dependencia: S, W, I-03. **Agent-doable**; inventario real por tienda = Discovery/negocio.

---

## 5. Secuencia sugerida (olas)

1. **Ola 1 — Fundación:** Fase **S** (segmentación). Barata, desbloquea todo.
2. **Ola 2 — Vehículos + Mayorista base:** Fase **V** (V-01..V-03) + Fase **M** (M-01, M-02, M-03). Es
   lo que POS/MPS pidió más explícito (trailers, entregas, mayorista).
3. **Ola 3 — Almacén + POS:** Fase **W-A** (integración LISA WMS — arranca apenas N'ware confirme el
   método; es el handoff de órdenes del stock propio) + Fase **W-B** (WMS propio en nodos sin LISA) +
   Fase **P** (tienda como fulfillment). *W-A puede adelantarse y correr en paralelo a Ola 2 si el método
   LISA se confirma pronto: desbloquea el flujo de órdenes del almacén central.*
4. **Ola 4 — Integraciones + Transportadoras:** Fase **I** (e-commerce/Magento, POS, EDI; LISA ya en W-A)
   + Fase **T** (tendering, rate-shopping, guía por carrier, webhooks).
5. **Ola 5 — C2C + Demanda:** Fase **C** (C2C completo) + Fase **D** (optimización/MPS, requiere datos
   reales de Discovery).

Cada contexto nuevo es **extraíble a microservicio** (ADR 001/008); se construye como monolito modular y
se parte solo cuando el volumen lo pida.

---

## 6. Decisiones (resueltas con el dueño — alcance COMPLETO)

El dueño confirmó "todo completo, mientras más mejor". Estado de las decisiones:

1. **Lisa = LISA WMS** (WMS certificado para SAP B1). ✅ resuelto *qué es*. **Queda por confirmar con
   N'ware el MÉTODO de integración** (DI-API / Service Layer de SAP B1 / EDI / vía SAP B1 como hub). Es
   el único bloqueo externo real y solo afecta W-A/I-04; el resto avanza sin eso.
2. **C2C real: SÍ, completo** — vendedores persona con KYC/payout/pickup/devoluciones. → Fase C completa.
3. **WMS: completo** — pero como **integración con LISA** (W-A) + WMS propio solo en nodos fuera de LISA
   (W-B). No se reconstruye el WMS de LISA.
4. **B2B flete/incoterms: SÍ** — la plataforma modela el dato logístico (incoterm, quién paga el flete,
   zona tarifaria) en M-06. El pricing comercial sigue en el comercio/SAP.
5. **EDI B2B: SÍ** (I-05). Sinergia: LISA ya trae EDI "plug-and-play"; alinear.
6. **Prioridad: todo** — se mantiene la secuencia de olas de §5 (S primero por ser fundación barata), sin
   recortar alcance.

**Único input pendiente del negocio/vendor:** el método de integración de LISA (decisión #1) y los
contratos/credenciales reales de cada sistema externo (SAP B1, LISA, carriers, e-commerce) — necesarios
para *conectar de verdad*, no para *diseñar y construir el esqueleto*.

---

## 7. Principios de implementación (para los agentes)

- **Aditivo y sin romper retail:** todo campo/segmento nuevo con default = comportamiento B2C actual.
- **Reutilizar aguas abajo:** planning/execution/telemetry/tracking se reusan; lo nuevo es aguas arriba
  (canal, unidad de manejo, cita) y restricciones (vehículo, POD).
- **Outbox para todo I/O externo** (Lisa, SAP, carriers, e-commerce): nunca HTTP síncrono en un request.
- **Contextos autónomos** (ADR 001/008): no acoplar; cablear por `logistics-outbox.routes` (como
  `inbound.goods_received`→inventory).
- **DoD de CLAUDE.md** por paquete y registro en los 6 lugares. Gates de CI: ver `docs/ci-quality-gates.md`.
- **Admin en inglés**, comentarios/docs en español, sin UUIDs visibles, producto siempre con nombre+SKU.

---

### Relación con documentos existentes
- `docs/vision.md` — visión y fases 0–3 originales (B2C + red Dismac).
- `docs/roadmap.md` — roadmap técnico vivo (Fases V/S/O/I/E/C/R/A/K/0/P/3).
- `docs/marketplace-dropship-roadmap.md` + ADR 017 — marketplace/dropship (C2C/B2B2C), Fases 0–5 hechas.
- Este documento extiende los anteriores hacia **B2B mayorista, WMS profundo, multimodal y canales**.
