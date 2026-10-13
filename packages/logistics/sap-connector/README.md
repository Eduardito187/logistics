# logistics/sap-connector — Conector SAP

## Responsabilidad

Capa anti-corrupción entre la plataforma y SAP (ADR 002). Es el **único** package que conoce SAP.
Traduce en ambos sentidos la coordenada SAP (material + centro + almacén) y el sku propio de la
plataforma:

- **Lectura**: lee pedidos de SAP y los traduce al modelo propio (DTO), sin acoplarse al contexto de
  envíos.
- **Escritura**: al recibir un bulto en un nodo, encola un traspaso de stock hacia SAP. El despacho es
  **asíncrono**, idempotente por `event_id`, con reintentos y panel de reconciliación. **El escaneo
  nunca espera a SAP**: si SAP cae, la operación sigue.

## No es responsable de

Validar stock. Normalizar pedidos al modelo propio (eso es del contexto de envíos: este conector solo
entrega DTOs). Despachar por HTTP síncrono dentro de un request de usuario: todo el I/O con SAP pasa por
el worker de despacho (el puerto `SapClient`, detrás del outbox).

## Estructura

Cuatro capas (ver `docs/architecture.md`), con `Domain` en PHP puro (sin Laravel):

- **Domain**
  - Agregado `StockTransfer` (raíz): `fromReception()` (nace Pending y graba `StockTransferEnqueued`),
    `markSent()`, `acknowledge()` (graba `StockTransferAcknowledged`), `markFailed()`. La clave de
    idempotencia es `eventId` (el event_id de la recepción de origen).
  - Entidad `MaterialMapping`: el mapa de traducción sku ↔ material + centro + almacén.
  - Value objects: `SapMaterial`, `SapCenter`, `SapWarehouse`, `SapOrderRef`, `TransferStatus`
    (`Pending`/`Sent`/`Acknowledged`/`Failed`), IDs tipados `MaterialMappingId` y `StockTransferId`.
  - Puerto `SapClient` (único que habla SAP), contratos `MaterialMappingRepository` y
    `StockTransferRepository`, excepción `SapConnectorException`.
- **Application** (CQRS)
  - Comandos: `ImportSapOrders` (lee de SAP y devuelve `ImportedOrderDto`), `EnqueueStockTransfer`
    (desde una recepción; idempotente por `eventId`), `DispatchPendingTransfers` (worker de despacho:
    empuja a SAP y marca Sent/Failed).
  - Consultas: `ListPendingTransfers`, `ListFailedTransfers` (reconciliación) con el read model
    `StockTransferView`.
- **Infrastructure**: `NullSapClient` (stub que loguea y devuelve `[]`), records y repos Eloquent con
  sus mappers, migraciones (`sap_material_mappings`, `sap_stock_transfers` con `event_id` único) y el
  `SapConnectorServiceProvider` que cablea todo.
- **Presentation**: comando `logistics:sap:dispatch-transfers` (worker de reconciliación).

## Estado

Dominio, aplicación (CQRS) e infraestructura (Eloquent) implementados. Pendiente:

- Integración real con el outbox: hoy los handlers liberan los eventos con `// TODO(outbox)` sin
  despacharlos.
- Cliente SAP real detrás de `SapClient` (hoy `NullSapClient`).
- Verificación contra los containers (tests + PHPStan nivel 8 + Pint): sin Docker disponible, queda
  pendiente; se dejó `php -l` limpio en todo el package.
