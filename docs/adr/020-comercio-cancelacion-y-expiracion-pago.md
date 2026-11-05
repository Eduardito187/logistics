# ADR 020 — Cancelación de órdenes (pre/post pago) y expiración de pago por método

**Status:** Accepted
**Fecha:** 2026-11-04
**Contexto:** cerrar las reglas de cancelación del frente comercial sobre el flujo
catálogo→carrito→checkout→pago→envío→entrega (ADR 018/019), incluyendo la expiración automática de
órdenes impagas. Cada contexto sigue autónomo: las reacciones cruzadas van por **outbox**.

## Decisiones

### 1. Se puede cancelar en cualquier estado no terminal (pre y post pago)
`Order::cancel(reason, source)` se permite en `Pending`, `Paid` y `Fulfilling` (idempotente; no sobre
`Cancelled`). La orden emite **`orders.order_cancelled`** con `{order_id, was_paid, reason, source}`.
- **Orígenes** (`source`): `pos`, `manual` (admin), `cron` (expiración), `customer`.
- **Canales**: API `POST /channels/orders/{order}/cancel` (POS / cliente), `POST
  /integrations/orders/{order}/cancel` (backend de POS / servicio), y **acción manual en el admin
  Filament**.

### 2. Reacción en logística según la etapa del envío (por outbox, idempotente)
Un dispatcher escucha `orders.order_cancelled` y actúa según el **estado del envío** (se halla por
`(source='commerce', externalId=orderId)`):
| Etapa (ShipmentStatus) | Acción |
|---|---|
| sin envío (impaga, nunca se hizo fulfillment) | nada en logística; se libera el cupo (ver 4) |
| `Received` / `Planned` (en preparación / por cargar, pre-despacho) | **`CancelShipment`** → saca del plan, libera cupo |
| `InExecution` (cargado / en el camión) | `CancelShipment` + **`InitiateReturn`** (retorno a almacén) |
| `Delivered` | **no se cancela**: `InitiateReturn` (devolución del cliente / logística inversa) |

`CancelShipment` (nuevo, en `shipments`) tiene guarda por estado: cancela salvo `Delivered`/`Failed`
(idempotente). Emite `shipments.shipment_cancelled`.

### 3. Reembolso automático si estaba pagada
Si `was_paid = true`, un dispatcher de `payments` despacha **`RefundPayment`** de la orden (idempotente).
(La aprobación manual del reembolso queda como mejora futura; hoy es automático.)

### 4. Liberación del cupo reservado
El cupo se reserva por **titular = `order_id`** (pre-pago, ver ADR 019 / `promise`). Al cancelar, un
dispatcher de `promise`/`capacity` **libera la reserva** del `order_id`. Al pagar, la reserva se consume
al crear el envío.

### 5. Expiración automática de impagas — cron configurable por método de pago
Config `config/commerce.php` → `cancellation.unpaid_expiry_minutes` por método (overridable por env):
```php
'cancellation' => [
    'unpaid_expiry_minutes' => [
        'qr_bcb'   => (int) env('COMMERCE_EXPIRY_QR', 360),      // 6 h
        'card'     => (int) env('COMMERCE_EXPIRY_CARD', 720),    // 12 h
        'transfer' => (int) env('COMMERCE_EXPIRY_TRANSFER', 1440),// 24 h
        'default'  => (int) env('COMMERCE_EXPIRY_DEFAULT', 720),  // 12 h
    ],
],
```
Comando `commerce:orders:expire-unpaid` (programado en el scheduler, cada N minutos): por cada orden
`Pending`, calcula la ventana según el **método del pago iniciado** (si hay) o el `default`; si venció
(desde `created_at` del pago, o de la orden si no hay pago), despacha `CancelOrder(source: cron,
reason: unpaid_timeout)`. Lee el método/fecha del pago por un **puerto de lectura** read-only (no acopla
el dominio de `payments`).

## Consecuencias
- Cancelación uniforme por API (POS), admin y cron; reacciones cruzadas desacopladas por outbox
  (fanout de `orders.order_cancelled` → fulfillment + refund + liberación de cupo).
- `shipments` gana `CancelShipment`; `orders` gana la expiración por método; se reutilizan
  `returns.InitiateReturn` y `payments.RefundPayment`.
- El gate de pago del fulfillment ya existe por construcción (sin pago no hay envío); esto suma la
  **reserva de cupo pre-pago** y su **liberación/expiración**.
