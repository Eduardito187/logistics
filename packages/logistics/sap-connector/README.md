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

## Avisos fire-and-forget a SAP

Principio del dueño: a SAP le importan el stock y los registros; a nuestra plataforma de despacho, no.
Tras cada hecho relevante se **avisa** a SAP sin esperar nada de vuelta:

1. `SapNotificationRecorder` (observador del outbox, no reactor) ve cada mensaje procesado; si su
   `event_name` está en `notifications.events`, registra UNA fila en `sap_notifications`
   (insert-or-ignore por `event_id`) y encola `SendSapNotificationJob` en la cola `sap`.
2. El job (`DeliverSapNotification`) hace `POST {base_url}{events_path}` con
   `{event_id, event_name, occurred_at, aggregate_id, payload}`, header `Idempotency-Key = event_id`,
   timeout corto (`notifications.timeout`, 5 s) y el breaker del grupo `sap`. 2xx ⇒ `sent` con la
   respuesta cruda (≤ 4 KB); otro código o error de red ⇒ `failed` con motivo y backoff.
3. `logistics:sap:retry-notifications` (scheduler, cada minuto) re-encola los `pending`/`failed` con
   intentos < `notifications.max_attempts` (3) y backoff vencido, en lotes chicos. Agotados, quedan
   `failed` y se dejan: sin dead-letter ni acción manual obligatoria (hay un «Retry» opcional en el panel).
4. Conector apagado (`sap.enabled = false`, que el panel overlaya desde `integrations.sap.enabled`) o
   sin `base_url` ⇒ el aviso se registra `skipped` y no se envía nada.

Nada de lo que haga SAP (errores, lentitud, caída) afecta a la plataforma: el observador nunca lanza,
el job nunca lanza, y todo corre en la cola `sap`, aislada del resto. Panel: **Integrations → SAP
notifications** (solo lectura, sin UUID). Configuración: **Configuration → Integrations → SAP
connector** (ruta de eventos, eventos a avisar, intentos máximos, timeout del aviso).

### Sin HTTP a SAP dentro de un request

- `POST /app/sap/stock-transfers/dispatch` y la acción «Dispatch pending transfers» solo encolan
  `DispatchStockTransfersJob` (cola `sap`) y responden 202.
- `POST /integrations/sap/orders/import` solo encola `ImportSapOrdersJob` y responde 202.
- Sin doble envío de traspasos: los nuevos los empuja solo el reactor del outbox
  (`SapMessageDispatcher`, que ya no relanza: marca `Failed`); el scheduler
  (`logistics:sap:dispatch-transfers`) reintenta únicamente los `Failed` con backoff vencido
  (`transfers.retry_backoff_minutes`); la corrida manual además rescata `Pending` estancados.

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
