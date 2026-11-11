---
name: tracking-lifecycle-state-machine
description: "Máquina de estados del seguimiento (ciclo de vida del pedido): VisibleStatus nuevo, transiciones estrictas, reactores del outbox que lo alimentan solo"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-11T08:24:02.702Z
---

El seguimiento (`packages/logistics/tracking`) es el CICLO DE VIDA del pedido de cara al cliente, con
transiciones estrictas (sin saltos) y alimentado AUTOMÁTICAMENTE por los hechos del sistema (2026-11-11).

**Vocabulario (`VisibleStatus`, reescrito):** `PENDING → PROCESSING → IN_PROGRESS → LOADED → ON_THE_WAY →
DELIVERED`, más `CANCELLED`, `RESCHEDULED`, `FAILED`, `RETURNED`. `allowedNext()`/`canTransitionTo()`
definen el grafo; `isTerminal()` = Cancelled|Returned (Delivered ya NO es terminal: puede → Returned;
Failed puede reintentar → OnTheWay). Reglas: tras el pago y antes de entregar se puede cancelar/reprogramar;
tras entregar, devolver.

**Agregado `ShipmentTracking`:** ahora se identifica por `orderReference` (nace al crear la orden, estado
Pending, `shipmentId` NULLABLE); `bindShipment()` liga el envío cuando el pago lo crea. `applyStatus()`
RECHAZA transiciones no admitidas (excepción); `canApply()` lo usan los reactores para SALTEAR (tolerante)
eventos tardíos/duplicados. Métodos de intención: markProcessing/InProgress/Loaded/OnTheWay/Delivered/
Failed/cancel/reschedule/markReturned. Migración aditiva: `order_reference` (string nullable, index),
`shipment_id` nullable, backfill de valores viejos (REGISTERED→PROCESSING, PREPARED→IN_PROGRESS,
ARRIVING→ON_THE_WAY) + order_reference desde `shipments.external_id` (quita sufijo `#n:`).

**Comandos nuevos (tracking):** `OpenTracking(orderReference)`, `BindShipmentToTracking(orderRef, shipmentId)`
(reconcilia multishipping: liga al unbound o abre hermano), `AdvanceTracking(status, shipmentId|orderRef)`
(avance tolerante; por orderRef aplica a TODOS los hermanos). `ProjectExecutionEvent` sigue para hechos de
ejecución por envío (ahora tolerante + cascada de stops en Delivered/Failed).

**Reactores del outbox (lo que lo alimenta solo)** en `tracking/src/Infrastructure/Outbox/`, registrados en
`packages/logistics/outbox/config/logistics-outbox.php` (fanout):
- `orders.order_placed` → OpenTracking (PENDING)
- `orders.order_paid` → PROCESSING · `orders.order_cancelled` → CANCELLED (tolerante si ya entregado)
- `shipments.shipment_received` → bind (orderRef del external_id)
- `labels.labels_emitted` → IN_PROGRESS (order_number = external_id)
- `execution.shipment_loaded` → LOADED · `execution.shipment_departed` → ON_THE_WAY ·
  `execution.shipment_delivered` → DELIVERED · `execution.delivery_failed` → FAILED
- `returns.return_initiated` → RETURNED · `shipments.shipment_rescheduled` → RESCHEDULED
Los eventos de ejecución/shipments van por ABANICO junto a `DomainEventNotificationDispatcher` (se preservó
el aviso al cliente). CLAVE: `labels.order_number` = `shipments.external_id` (lo confirma
`EloquentLabelUnitResolver`); el `orderReference` es la parte anterior a `#n:`.

**Piezas nuevas (fuera de tracking):**
- Execution: evento `ShipmentDeparted` (`execution.shipment_departed`) + `Delivery::markDeparted()`
  (Loaded→InTransit, idempotente) + comando `MarkShipmentDeparted` + endpoint **POST /app/driver/route/start**
  (`DriverRouteController::start()`: marca salida de todos los envíos de la ruta del chofer → «en camino»).
- Shipments: comando `RescheduleShipmentDelivery` + evento `ShipmentRescheduled` + `Shipment::reschedule()`
  (cambia la franja salvo entregado/fallido/cancelado) + acción Filament «Reschedule delivery» en ShipmentResource.

**Decisiones del dueño:** FAILED se mantiene; «Llegando»/ARRIVING se FUSIONÓ en ON_THE_WAY; «en camino» lo
dispara el chofer al iniciar la ruta; el tracking arranca en PENDING al crear la orden.

**DoD:** pest verde (suite completa + `TrackingLifecycleTest` de integración), PHPStan 8 limpio repo-wide,
Pint limpio. Fronts actualizados: `resources/js/Pages/Track/Show.vue` (statusLabel/tone) y el stepper Blade
`packages/logistics/tracking/resources/views/public.blade.php` ($statusLabels/$stages). OpenAPI `VisibleStatus`
ampliado. Relacionado con [[multishipping]], [[no-destructive-db-ops]], [[marketplace-roadmap]].
