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
    (desde una recepción; idempotente por `eventId`), `AcknowledgeStockTransfer` (callback de SAP que
    confirma un traspaso; idempotente), `DispatchPendingTransfers` (worker de despacho: empuja a SAP y
    marca Sent/Failed), `DefineMaterialMapping` (define/actualiza el mapa de traducción; idempotente
    por sku).
  - Consultas: `ListPendingTransfers`, `ListFailedTransfers` (reconciliación) con el read model
    `StockTransferView`; `ListMaterialMappings` con el read model `MaterialMappingView`.
- **Infrastructure**: puerto `SapClient` con dos adaptadores detrás del contrato — `HttpSapClient`
  (cliente real, único que habla HTTP con SAP, con header de idempotencia) y `NullSapClient` (stub que
  loguea y devuelve `[]`); el `SapConnectorServiceProvider` elige HTTP si hay `base_url` configurada y
  si no cae al Null (el escaneo nunca depende de SAP). Adapter del relay `Outbox\SapMessageDispatcher`
  que cierra la cadena **evento → outbox → SAP**: el relay le entrega los hechos del conector (prefijo
  `sap_connector.`) y empuja a SAP cada `stock_transfer_enqueued` por el puerto `SapClient`, idempotente
  (ignora reentregas de traspasos ya enviados y los hechos que no son de encolado) y relanzando ante un
  fallo para que el relay reintente con backoff. El provider registra esa ruta en el ruteo del outbox
  (`logistics-outbox.routes`) sin tocar la config del outbox, para dejar el conector autocontenido.
  Records y repos Eloquent con sus mappers,
  factories por modelo, migraciones (`sap_material_mappings`, `sap_stock_transfers` con `event_id`
  único) y `config/logistics-sap-connector.php` (endpoint y credenciales SAP por env; centro/almacén
  por defecto configurable, P2-SAP-06).
- **Presentation**:
  - Comando `logistics:sap:dispatch-transfers` (worker de reconciliación).
  - Controllers HTTP por caso de uso en el fragmento `routes/api/sap-connector.php`: puerta
    `integrations` (importar pedidos, acknowledge de SAP) y puerta `app` (encolar traspaso,
    reconciliación, administrar el mapa de materiales).
  - Filament: `StockTransferResource` (panel de reconciliación, solo lectura, con disparo de despacho)
    y `MaterialMappingResource` (CRUD del mapa de traducción). Fragmento OpenAPI en
    `openapi/paths/sap-connector.yaml`.
- **Seeder**: `Infrastructure\Persistence\Seeders\SapConnectorSeeder` con datos de demo (mapa de
  traducción realista y traspasos en todos los estados), idempotente; lo integra el `DatabaseSeeder`.

## Estado

Dominio, aplicación (CQRS), infraestructura (Eloquent + adaptador SAP real y stub + adapter del relay
`SapMessageDispatcher` que cierra evento → outbox → SAP), presentación (HTTP, consola y Filament),
factories, seeder y tests (Unit del agregado, del mapeo y del dispatcher; Feature del flujo de outbox e
idempotencia) implementados.

Verificado en este cambio (sin correr la suite completa, por acuerdo del dueño): `php -l` limpio sobre
lo tocado, PHPStan nivel 8 limpio sobre `packages/logistics/sap-connector/src` (0 errores), Pint limpio
sobre los archivos tocados, y boot real del container confirmando que la ruta `sap_connector.` queda
registrada en el ruteo del outbox, que el `MessageDispatcher` es el `RoutingMessageDispatcher`, que el
`SapMessageDispatcher` resuelve del contenedor y que el `SapClient` por defecto es `NullSapClient`.

Pendiente:

- Credenciales reales de SAP en el gestor de secretos (P1-INFRA-04) para activar `HttpSapClient`.
