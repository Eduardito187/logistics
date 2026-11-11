---
name: multishipping
description: Multishipping — una orden se reparte en varios envíos (uno por destino); modelo order_reference + agrupación en el dispatcher + reader/enricher/tracking por hermanos
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-05T13:57:24.278Z
---

Una orden de comercio puede ir a **varios destinos** (unas líneas a domicilio, otras a retiro en
tienda/CD). Se materializa como **varios envíos hermanos** (uno por destino), NO como destino por línea
dentro de un solo envío (planning/ejecución/tracking son por envío). Diseño aprobado por el dueño ("yo
diseño"). Implementado en `feature/commerce-fase0` (2026-11-05).

**Modelo de datos.** `shipments.order_reference` (migración aditiva, nullable, indexada, backfill =
`external_id`) agrupa los hermanos. El `external_id` sigue siendo la clave de idempotencia ÚNICA por
fuente: el grupo a domicilio conserva `external_id = orderId` (retrocompatible); cada grupo-nodo lleva
`external_id = "{orderId}#n:{nodeId}"`. En un solo destino `order_reference == external_id` → flujo
idéntico al anterior. `Shipment` agregado: campo `orderReference` (cae a `sourceReference->externalId`),
getter `orderReference()`, en `receive()`/`reconstitute()`; `ReceiveShipment` comando + handler lo pasan;
`ShipmentMapper` persiste/hidrata `order_reference`; `@property` en `ShipmentRecord`.

**Ingesta.** `OrderLine` (commerce) gana `destinationNodeId` opcional (null = domicilio de la orden).
`OrderPaidShipmentDispatcher` agrupa las líneas por destino y despacha **un `ReceiveShipment` por grupo**,
todos con `orderReference = orderId`. Ver [[marketplace-roadmap]] (ownership) y [[access-iam-context]].

**Lectura.** `EloquentDispatchLinesReader` y `EloquentLabelEnricher` ya NO colapsan a un envío:
`shipmentsForOrder()` trae TODOS los hermanos (por `order_reference`, cae a `external_id`). El reader arma
un grupo de destino por hermano + detalle plano con el `shipment_id`/destino de cada línea. El enricher
resuelve cada etiqueta contra el hermano que realmente contiene su bulto (guard ordinal+sku); destino por
defecto = primer hermano (preserva un-solo-envío). Las etiquetas numeran la línea DENTRO de su envío
(EmitShipmentLabels es por envío) → la clave de etiqueta se acota por `dst` del QR y cae a nivel orden.

**Tracking.** `GetShipmentUnitsHandler::resolveLabelIds` busca etiquetas por `order_reference` (no
`external_id`, que en hermanos es compuesto). Ver [[telemetry-context]] no aplica; el puente etiqueta↔bulto
está en [[product-labeling-rules]] / enriquecimiento [[api-readmodel-enrichment]].

**Sin cambio de contrato API**: el envelope dispatch-lines ya tenía `destinations[]`; no se tocó OpenAPI
por multishipping (la regen pendiente del app es por el puente unit_id/label_id/resolve de Frente 1).

**DoD**: PHPStan 8 limpio, Pint limpio, suites labels/shipments/tracking/commerce-orders verdes (275
tests/881 aserciones). Migración aplicada a dev (295 envíos backfilled, 0 null). Tests nuevos:
`OrderPaidShipmentDispatcherTest` (agrupación), `DispatchLinesTest` (multishipping: 2 grupos).
