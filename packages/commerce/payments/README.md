# commerce/payments

Contexto de **pagos** del frente comercial (EC-F0-07 del roadmap de ecommerce; familia `commerce/*`
de ADR 018). Reutiliza el núcleo `Logistics\Shared\*` y el `Logistics\Outbox\*`, e integra con el resto
por outbox/puertos: nunca llama al Domain/Infrastructure de un contexto logístico ni al revés.

## Responsabilidad

El **pago** como agregado de primera clase: iniciar un cobro contra una orden, autorizar/capturar/fallar/
reembolsar, y custodiar las invariantes de transición de estado. El cobro real se hace detrás del puerto
`PaymentGateway` (pasarela enchufable). El hecho `payments.captured` es el que, vía outbox, confirmará la
orden pagada aguas abajo (puente OMS→Shipment de ADR 018).

## No-responsabilidad

- NO conoce el contexto `orders`: referencia la orden por `Uuid` genérico (puerto/evento, no dependencia).
- NO guarda datos de tarjeta (PCI: solo referencia/token del proveedor — `gateway_ref`).
- NO toca SAP directo (ADR 002): si un cobro debe reflejarse en SAP, va por el conector vía outbox.
- NO cobra con servicios de pago de pago (ADR 013/014/015): pasarelas open-source / locales tras el puerto.

## Estado

EC-F0-07 — en construcción. DDD + CQRS + Clean Architecture, UUID v7, outbox (ADR 008/018).
Pasarela por defecto: `StubPaymentGateway` (aprueba siempre, para dev/tests). `QrBcbPaymentGateway` es
un placeholder documentado que hoy delega al stub; el binding se elige por env `PAYMENTS_GATEWAY`.
