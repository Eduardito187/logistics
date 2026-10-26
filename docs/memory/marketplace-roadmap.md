---
name: marketplace-roadmap
description: "Iniciativa marketplace/dropship/fulfillment por sellers: ADR 017 + roadmap por fases; Fase 0+1 hechas, 2-5 pendientes"
metadata:
  node_type: memory
  type: project
  originSessionId: 95be9a11-8872-4d3d-b8b2-ef0548264b66
  modified: 2026-10-16T22:35:08.655Z
---

Iniciativa para operar producto que **no es de Dismac** (hasta ahora todo era retail `OWNED`). Decisión en
**`docs/adr/017-marketplace-dropship-fulfillment-sellers.md`**; plan punto por punto en
**`docs/marketplace-dropship-roadmap.md`**. Modos: `OWNED` (retail), `FBD` (seller deja en almacén central,
Dismac distribuye), `DROPSHIP` (seller despacha). **Idea rectora:** una vez recibida y **etiquetada** en un
almacén central, la unidad es operacionalmente idéntica a una Dismac → todo lo de aguas abajo
(planning/execution/telemetry/última milla) se REUTILIZA; lo nuevo es aguas arriba (entrada + propiedad).

**Fundación transversal (hecha):** VO `Logistics\Shared\Domain\ValueObject\Ownership` + enum
`FulfillmentMode` (`Owned|Fbd|Dropship`). El dueño se guarda como `Uuid` genérico (shared NO depende de
`sellers`). Invariante: owned ⇒ dueño null; fbd/dropship ⇒ dueño no null.

## Estado por fase (2026-10-16)

- **Fase 0 — HECHA:** ADR 017 escrito (Accepted).
- **Fase 1 — HECHA y verde:** contexto nuevo **`sellers`** (`packages/logistics/sellers`, 62 archivos, 4
  capas; agregado `Seller`, VOs `SettlementTerms`/`SellerSku`/`ContactInfo`/`Money`/`SellerStatus`/
  `PaymentCycle`; CQRS Register/Activate/Suspend/SetSettlementTerms/UpsertSellerSku + queries; eventos
  `sellers.*` al outbox; `SellerResource` grupo Commerce; API en puerta `integrations`
  `/api/v1/integrations/sellers*`). Registrado en los 6 lugares. Campos aditivos de propiedad en
  `shipments` + `shipment_units` (migración 2026_10_16, VO cableado, default owned, retail intacto).
  Verificación: Pest sellers 18/58, Pest shipments 98/288 (sin romper), PHPStan 8 = 0, Pint PASS,
  migrate aditivo OK, render admin 64/64 200, seed demo idempotente (3 sellers).
- **Fase 2 — HECHA y verde:** contexto `inbound` (`packages/logistics/inbound`, ASN + GoodsReceipt + QC +
  etiquetado; cockpit "Inbound board" + AsnResource + ReceivingCalendar; emite `inbound.goods_received` y
  `inbound.units_labeled`). `network` ganó `NodeType::CentralWarehouse` + agregado `ReceivingDock`; `capacity`
  ganó el eje **inbound** (`BucketScope::forReceivingDock`, unit `appointments`, `GetReceivingDockCalendar`).
- **Fase 3 — HECHA y verde:** contexto `inventory` (`StockLot` con `Ownership`, receive/reserve/pick/transfer,
  ATP, ledger `inventory_movements`, segregación lógica). `sap-connector` ganó `TransferKind` owned/consignment
  (regla dura: la consignación NO se postea como material propio) + `seller_id` en material mappings.
  **Cableado cross-context**: `inbound.goods_received` → `GoodsReceivedStockDispatcher` (en la config
  `logistics-outbox.routes`) → `ReceiveStock`. VERIFICADO end-to-end (relay → crea lote con dueño).
- **Fase 4 — HECHA y verde:** contexto `settlement` (`SellerSettlement` clona `carriers/Settlement`; draft/
  confirm/pay; lee despachos del seller de `shipments` por `owner_party_id` read-only; fees de almacenaje/
  fulfillment/última milla tipados en 0, hueco diferido). + contexto `returns` (logística inversa:
  `ReturnOrder`, retorno de cliente / return-to-vendor, disposición restock/return-to-seller/scrap).
  **Cableado**: `returns.return_dispositioned` (restock) → `ReturnRestockDispatcher` (en inventory) →
  `ReceiveStock` (reingreso al lote del dueño). VERIFICADO end-to-end.
- **Fase 5 — HECHA y verde:** `promise` suma lead time de marketplace (FBD/dropship; retail intacto; gancho
  a `sellers.fulfillment_lead_days` y a ATP de inventory). `execution` tiene la rama DROPSHIP por
  `fulfillment_mode` (`DropshipHandling` TrackOnly/ThirdPartyCarrier/LastMile; en no-LastMile se prohíben los
  escaneos propios y se cierra por confirmación del seller). `sellerportal` (package nuevo, solo lectura:
  ASNs/stock/liquidaciones del seller; el sellerId sale del scope de permiso del token de `access`:
  `sellerportal.seller.<uuidhex>`, 403 si abarca ≠1 seller).

ROADMAP MARKETPLACE COMPLETO (Fases 0-5). Contextos nuevos totales: `sellers`, `inbound`, `inventory`,
`settlement`, `returns`, `sellerportal` (+ aditivos en `network`, `capacity`, `sap-connector`, `shipments`,
`promise`, `execution`). Cableado cross-context por outbox config (`logistics-outbox.routes`):
`inbound.goods_received`→`GoodsReceivedStockDispatcher`, `returns.return_dispositioned`→`ReturnRestockDispatcher`,
ambos → inventory `ReceiveStock`. Verificación global final: PHPStan 8 = 0, Pint PASS (3032 files), render
admin 70/70, Pest de los contextos verde (incl. 154 passed returns+sellerportal+execution), cableados
probados end-to-end por relay.

Registro (los 3 packages nuevos en los 6 lugares) + merge de openapi/es.json + cableado de subscribers, a
cargo del orquestador. Verificación global tras ensamblar: PHPStan 8 = 0, Pint PASS (2950 files), render
69/69, Pest de los contextos verde. GOTCHA que mordió: el adaptador `CapacityDockQuotaReserver` de inbound
se codificó contra el eje node (cuando capacity aún no tenía el eje inbound) — el orquestador lo realineó a
`ConsumeCapacityCommand(warehouseId:..., unit:'appointments')`. Otro: un fixture de test de settlement
destructuraba mal (`quantity` tomaba el valor equivocado) → gross 40000 vs 1_000_000; corregido.

Patrón de build: me apoyé en la skill [[bounded-context]]; olas de agentes (1 por el contexto completo + 1
por los campos aditivos de shipments), con la registración de los 6 lugares y la verificación global a cargo
del orquestador. Relacionado: [[admin-ops-first-redesign]], [[multisession-shared-tree]].
