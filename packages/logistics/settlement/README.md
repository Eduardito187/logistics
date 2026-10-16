# logistics/settlement — Liquidación de sellers (marketplace, Fase 4)

Contexto que **liquida a los sellers de marketplace**: por cada periodo, agrega los despachos del seller,
aplica la comisión y los fees pactados y calcula el **neto a pagar**. Clona el patrón de
`carriers/Settlement` (ver ADR 017, `docs/marketplace-dropship-roadmap.md` §7).

## Responsabilidad

- Agregado `SellerSettlement`: periodo, líneas (un despacho = una línea con su monto base), fees aplicados
  (comisión en basis points + fee fijo por despacho + huecos tipados de almacenaje/fulfillment/última
  milla), neto a pagar, estado `Draft | Confirmed | Paid`.
- Escritura por comandos (`DraftSellerSettlement`, `ConfirmSettlement`, `MarkSettlementPaid`) y lectura por
  consultas (`GetSettlement`, `ListSettlements`, `GetSellerStatement`) con ReadModels.
- Los eventos de dominio (`settlement.drafted`, `settlement.confirmed`, `settlement.paid`) salen por el
  outbox transaccional, nunca por I/O síncrono.

## NO responsabilidad

- No calcula ni cobra impuestos; no ejecuta el pago real (solo registra su referencia).
- No conoce el agregado `Seller`: los términos (comisión/fee) llegan como **input del comando**.
- No depende por composer de `shipments`/`execution`/`inventory`: lee los despachos del seller de la tabla
  `shipments` en **modo solo lectura y en lote** (`DB::table(...)` con `try/catch`, patrón del skill para
  analítica cross-context).

## Hueco de fees diferidos (TODO Fases 2–3)

`storage_fee`, `fulfillment_fee` y `last_mile_fee` existen como columnas y como campos tipados del VO
`AppliedFees`, pero **hoy valen siempre 0**. Se poblarán cuando los contextos `inventory` (almacenaje) e
`inbound`/última milla expongan sus costos por seller/periodo. El neto a pagar ya los descuenta (hoy sin
efecto), así el contrato de datos no cambia cuando se activen.

## Estado

Núcleo de Fase 4 (liquidación). La logística inversa (devoluciones / `ReturnOrder`) queda fuera de este
núcleo (depende de contextos aún no construidos).
