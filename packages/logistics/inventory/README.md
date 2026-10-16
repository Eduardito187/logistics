# logistics/inventory

Contexto de **inventario con atribución de dueño** (Fase 3 del roadmap marketplace, ADR 017).

## Responsabilidad

- Saber **de quién es cada lote de stock** (propio de Dismac vs. consignación de un seller), aunque el
  stock esté físicamente mezclado en el almacén: **segregación lógica, no física** — la propiedad vive en
  el lote (`StockLot`), no en una ubicación física separada.
- Operaciones de stock sobre el lote: `receive`, `reserve`, `release`, `pick`, `transfer` (entre nodos),
  `adjust`.
- Responder, por almacén / sku / **dueño**, el stock on-hand, el listado de lotes y el Available-to-Promise.

## No-responsabilidad

- **No** recibe físicamente al proveedor (eso es `inbound`): el enganche es el evento `inbound.goods_received`,
  que este contexto consume para crear el lote con el dueño correcto (subscriber `ReceiveStockOnGoodsReceived`).
- **No** postea a SAP: la dimensión de propiedad la aplica el `sap-connector` (nunca se postea stock de
  seller como material propio de Dismac).
- **No** liquida al seller (eso es `settlement`).

## Arquitectura

DDD + CQRS + Clean Architecture (ADR 008). Domain PHP puro (sin Laravel). Escritura por comandos, lectura
por ReadModels optimizados. UUID v7 en toda identidad. Todo evento de dominio sale por el outbox
transaccional. Índices pensados para millones de lotes: `(warehouse_id, sku, owner_party_id)` y
`(warehouse_id, sku, fulfillment_mode)`.

## Estado

Fase 3 construida: Domain + Application (CQRS) + Infrastructure + Presentation (Filament). El registro del
package (6 lugares del §9 del roadmap) lo hace el orquestador.
