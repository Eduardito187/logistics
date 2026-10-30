# commerce/invoicing

Contexto de **facturación / documento fiscal** del frente comercial (EC-F0-10 del roadmap de ecommerce;
familia `commerce/*` de ADR 018). Reutiliza el núcleo `Logistics\Shared\*` y el `Logistics\Outbox\*`, e
integra con el resto por outbox/puertos: nunca llama al Domain/Infrastructure de un contexto logístico ni
al revés, ni a otra familia de comercio.

## Responsabilidad

La **factura** como agregado de primera clase: calcular el documento desde las líneas de una orden pagada,
separar el IVA boliviano (13 % "por dentro", VO `BolivianTax`), emitir el documento fiscal obteniendo el
número del sistema tributario (puerto `TaxDocumentGateway`) y custodiar las invariantes de transición
(Draft → Issued → Cancelled). El hecho `invoicing.invoice_issued` se publica al outbox para reflejarse
aguas abajo (SAP por el conector, notificación al cliente), nunca con HTTP síncrono.

### Descomposición del IVA (regla)

El precio al público en Bolivia ya incluye el IVA. Dado el total bruto:

- `neto = round(bruto / 1,13)` (solo se redondea el neto, medio hacia arriba),
- `IVA = bruto − neto` (por diferencia, para que `neto + IVA === bruto` siempre).

Ej.: bruto 113,00 Bs → neto 100,00 / IVA 13,00. Coherente con el `TaxPolicy` de `commerce/pricing`, pero
este contexto replica el cálculo (acoplamiento cero entre contextos).

## Integración (puente orders.order_paid → invoicing)

`OrderPaidInvoiceDispatcher` (Infrastructure/Dispatch) consume el evento de integración
`orders.order_paid`, lee la orden de la tabla `commerce_orders` en modo read-only (sin `use` ni
dependencia a `orders`), mapea sus líneas y despacha `IssueInvoiceForOrder` por el CommandBus. Es
idempotente por `order_id`: una orden factura a lo sumo una vez. El ruteo del dispatcher en el outbox lo
registra el dueño del archivo `packages/logistics/outbox/config/logistics-outbox.php`.

## No-responsabilidad

- NO conoce los contextos `orders`/`payments`: referencia la orden por `Uuid` genérico (puerto/evento).
- NO toca SAP directo (ADR 002): si la factura debe reflejarse en SAP, va por el conector vía outbox.
- NO cobra ni integra servicios de pago (ADR 013/014/015): el número fiscal se obtiene tras el puerto
  `TaxDocumentGateway` con adaptadores open-source / locales.

## Estado

EC-F0-10 — en construcción. DDD + CQRS + Clean Architecture, UUID v7, outbox (ADR 008/018). Adaptador
tributario por defecto: `StubTaxDocumentGateway` (genera `FAC-<timestamp>-<hex>`, para dev/tests); el
binding se elige por env `INVOICING_GATEWAY`.
