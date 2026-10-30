# commerce/orders

OMS del frente comercial (ADR 018, ítem EC-F0-06). **Punto de convergencia comercio ↔ logística.**

## Responsabilidad

- Agregado `Order`: instantánea de líneas (precio congelado) + datos de entrega, ciclo de vida
  `pending → paid → fulfilling` (o `cancelled` desde pending).
- **Puente OMS → Shipment** (lo nuevo y clave): cuando el pago se confirma, la orden crea un envío
  reutilizando el contexto `shipments`, **por outbox** (nunca llamada directa).

## Puente por outbox (adaptadores en Infrastructure)

1. `payments.captured` → `PaymentCapturedOrderDispatcher` → comando `MarkOrderPaid` → la orden pasa a
   `paid` y emite `orders.order_paid`.
2. `orders.order_paid` → `OrderPaidShipmentDispatcher` → crea el envío con `ReceiveShipment`
   (contexto `shipments`), **idempotente por `(source='commerce', externalId=orderId)`**, y pasa la
   orden a `fulfilling` (`MarkOrderFulfilling`).

El dominio de `orders` NO conoce `payments` ni `shipments`: los adaptadores viven en Infrastructure y
hablan por el `CommandBus`/eventos. El acoplamiento a `shipments` (solo en el adaptador) se vuelve una
llamada de red el día que comercio se extraiga a su propio servicio.

## CQRS

- Comandos: `PlaceOrder` (checkout), `MarkOrderPaid`, `MarkOrderFulfilling`, `CancelOrder`.
- Consultas: `GetOrder`, `ListOrders` (filtro por estado) → `OrderView`.

## Idempotencia

El outbox reentrega at-least-once. `markPaid`/`markFulfilling` son idempotentes en el agregado, y
`ReceiveShipment` lo es por `(source, externalId)`: un `payments.captured` reentregado no duplica ni el
pago de la orden ni el envío.

## Estado

Dominio + CQRS + persistencia + puente + tests Unit/Feature. DoD verde en CI.
