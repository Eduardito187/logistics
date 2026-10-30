# Roadmap — Ecommerce B2B / B2C / C2C

> **Estado: Fase 0 COMPLETA en backend** (auditado 2026-10-30): los 11 ítems de código construidos,
> cableados (rutas, outbox, bindings) y con contrato en `openapi/v1.yaml`. **Pendiente para la DoD
> plena:** CI verde (Pest/PHPStan nivel 8/Spectator — no corre local sin Docker), la **emisión fiscal
> ante el SIN** (externo, vía `TaxDocumentGateway`) y el **storefront** (EC-F0-12, delegado a Magento —
> ver `docs/storefront-magento-brief.md`). Decisiones cross-context en **ADR 019**. Las fases
> B2C/B2B/C2C siguen pendientes. Fecha de alta: 2026-10-29.

Este roadmap cubre la **capa de comercio** (tienda, catálogo, carrito, checkout, pagos, cuentas) sobre
la plataforma. El backend actual (`logistics`) ya resuelve **fulfillment, última milla, flota, ruteo,
tracking y marketplace/dropship de sellers** (OWNED/FBD/DROPSHIP, ADR 017). Lo que falta — y es lo que
este documento planifica — es el **frente comercial** en sus tres modelos:

- **B2C** — venta a consumidor final (retail online).
- **B2B** — venta a empresas (mayorista, listas de precio, crédito, cotizaciones, aprobaciones).
- **C2C** — mercado entre particulares (un vendedor-persona publica, otro compra; reputación y escrow).

> **Regla transversal (del dueño):** stack **100% open-source y gratuito, sin servicios de pago**
> (coherente con ADR 013/014/015). Pagos: pasarelas locales/open-source o integración por adaptador.

## Leyenda
- `[ ]` pendiente (todo este roadmap). `[~]` en progreso. `[x]` hecho y verde (DoD).
- IDs estables para citar en commits/PRs/issues (`EC-B2C-03`, etc.).
- **DoD** (igual que `CLAUDE.md`): tests verdes + PHPStan 8 + Pint + verificación real contra containers;
  endpoint nuevo → `openapi/v1.yaml` en el mismo cambio; package nuevo → registrado en los 2 lugares;
  admin en español (`__()`), código en inglés; sin UUIDs en el admin.
- **Arquitectura** (igual que el resto): DDD + CQRS + Clean Architecture, un bounded context por package
  en `packages/logistics/*` (o `packages/commerce/*` si se decide separar), UUID v7, eventos→outbox,
  reutilizando el núcleo `logistics/shared`. Ver skill `.claude/skills/bounded-context`.

---

## FASE 0 — Fundaciones de comercio (transversal a los 3 modelos)

- `[x]` EC-F0-01 **ADR**: decidir si el comercio es un dominio nuevo (`packages/commerce/*`) o extiende
  `logistics/*`; límites con `sellers`, `inventory`, `shipments`, `access`. → **ADR 018** (`docs/adr/018-comercio-dominio-separado-commerce.md`): familia `packages/commerce/*`, namespace `Commerce\`, integración por outbox/puertos.
- `[x]` EC-F0-02 **Catálogo / PIM** (`packages/commerce/catalog`): dominio+CQRS+persistencia+tests;
  **API** (`GET /channels/catalog/products|/{id}|/categories`), **admin Filament** (Product/Category
  despachando Commands), **OpenAPI**, y **ATP** por puerto `ProductAvailabilityReader` (lee `inventory`
  por SKU, read-only) → `GET /channels/catalog/products/{id}/availability`.
- `[x]` EC-F0-03 **Pricing** (`packages/commerce/pricing`): listas por canal + IVA 13% / IT 3%.
  **API** `channels` (`GET /pricing/price` con desglose de impuestos, `/prices`) + `integrations`
  (gestión de listas) + OpenAPI. Aplicación de precio **client-side** (ADR 019).
- `[x]` EC-F0-04 **Cuentas** (`packages/commerce/accounts`): tipos persona/empresa/seller-persona +
  direcciones. **API** `channels` (register/get/by-user/addresses) + OpenAPI. Vínculo con `access` por
  referencia `user_id` (ADR 019).
- `[x]` EC-F0-05 **Carrito** (`packages/commerce/cart`): **API** (open/get/add/update/remove, con
  chequeo de disponibilidad), checkout→order (ver 06). Precio congelado client-side.
- `[x]` EC-F0-06 **Checkout + OMS** (`packages/commerce/orders`): **API** (`POST /channels/orders`,
  `GET /{order}`) + **`POST /channels/checkout`** (orden desde el carrito). Conversión del carrito
  **desacoplada por outbox** (`orders.order_placed` lleva `cart_id` → `OrderPlacedCartConverter`).
  Puente **orden pagada → Shipment** (`ReceiveShipment`, idempotente) + factura, por fanout de outbox.
- `[x]` EC-F0-07 **Pagos** (`packages/commerce/payments`): `PaymentGateway`+`StubPaymentGateway`,
  **inicio de pago** `POST /channels/payments` (devuelve `gateway_ref`), **webhook** de captura
  (`integrations`) → `payments.captured` → (fanout) marca la orden pagada + avisa al cliente.
- `[x]` EC-F0-08 **Búsqueda** (`packages/commerce/search`): **PostgreSQL FTS** (tabla de proyección
  `commerce_product_search` + GIN), reader con `websearch_to_tsquery`/`ts_rank` (keyset numérico exacto)
  y fallback ILIKE, comando `commerce:search:reindex`, indexado incremental por `catalog.product_*`
  (outbox). **API** `GET /channels/search/products|facets` + OpenAPI.
- `[x]` EC-F0-09 **Promociones** (`packages/commerce/promotions`): motor de reglas (descuentos/cupones/
  envío gratis). **API** `channels` (`POST /promotions/evaluate` para el storefront) + `integrations`
  (CRUD/activate/expire/rules) + OpenAPI.
- `[x]` EC-F0-10 **Impuestos / Facturación** (`packages/commerce/invoicing`): factura emitida al pagar
  la orden. **API** (get/by-order/cancel) + **documento HTML imprimible**. ⚠️ La **emisión fiscal real
  ante el SIN** (CUF/CUFD/firma) es **pendiente externo** (vía `TaxDocumentGateway` + conector).
- `[x]` EC-F0-11 **Notificaciones comerciales** (`logistics/notifications`): `CommerceEventNotification
  Dispatcher` + plantillas ES (pedido recibido / pago confirmado / pago fallido); cableado en outbox
  (`orders.order_placed`, `payments.captured` por fanout; `payments.failed`).
- `[→]` EC-F0-12 **Storefront**: delegado al **equipo de Magento** (no se construye en este repo). Brief
  autocontenido con el contrato en `docs/storefront-magento-brief.md`.

## FASE B2C — Retail a consumidor final

- `[ ]` EC-B2C-01 Home/tienda, categorías, ficha de producto con medios y stock (ATP).
- `[ ]` EC-B2C-02 Carrito + checkout invitado y logueado; cálculo de envío por cobertura (`coverage`) y
  promesa de entrega (`promise`) ya existentes.
- `[ ]` EC-B2C-03 Pago online (EC-F0-07) + confirmación; orden → shipment automático.
- `[ ]` EC-B2C-04 Cuenta del cliente: pedidos, direcciones, seguimiento (tracking per-bulto ya existe),
  devoluciones (reusa `returns`).
- `[ ]` EC-B2C-05 Reseñas y calificaciones de producto (moderación básica).
- `[ ]` EC-B2C-06 Lista de deseos / favoritos; recompra.
- `[ ]` EC-B2C-07 Carritos abandonados + recuperación (notificación).
- `[ ]` EC-B2C-08 Métricas B2C: conversión, ticket promedio, productos top (dashboard).

## FASE B2B — Venta a empresas

- `[ ]` EC-B2B-01 Cuenta empresa: organización compradora, usuarios/roles (comprador, aprobador, admin),
  centros de costo (extiende `access`).
- `[ ]` EC-B2B-02 Listas de precio y contratos por cliente; precios negociados; mínimos de compra.
- `[ ]` EC-B2B-03 Cotización / RFQ: solicitud de cotización → oferta → conversión a orden.
- `[ ]` EC-B2B-04 Flujos de aprobación (límites de monto, aprobador obligatorio).
- `[ ]` EC-B2B-05 Crédito / términos de pago (net 30/60), límite de crédito, estado de cuenta.
- `[ ]` EC-B2B-06 Compra por volumen / órdenes recurrentes / plantillas de pedido / reabastecimiento.
- `[ ]` EC-B2B-07 Facturación B2B y conciliación de pagos; integración con `settlement`/contabilidad.
- `[ ]` EC-B2B-08 Catálogo restringido por cliente (qué puede ver/comprar cada empresa).
- `[ ]` EC-B2B-09 Portal B2B (SPA) o extensión del storefront con modo empresa.

## FASE C2C — Mercado entre particulares

- `[ ]` EC-C2C-01 Vendedor-persona: onboarding/KYC ligero, extiende `sellers` para personas naturales.
- `[ ]` EC-C2C-02 Publicación de avisos/listings (producto usado/nuevo), medios, estado, moderación.
- `[ ]` EC-C2C-03 Descubrimiento: búsqueda/categorías de listings C2C (reusa EC-F0-08).
- `[ ]` EC-C2C-04 Checkout C2C con **escrow** (retención del pago hasta confirmación de entrega).
- `[ ]` EC-C2C-05 Fulfillment C2C: usar la red logística (recojo en nodo + última milla) o envío directo
  del vendedor; etiquetado en nodo central vuelve la unidad operacionalmente idéntica (patrón ADR 017).
- `[ ]` EC-C2C-06 Reputación: calificaciones bidireccionales comprador↔vendedor, historial, badges.
- `[ ]` EC-C2C-07 Resolución de disputas / reclamos (mediación, reembolso desde escrow).
- `[ ]` EC-C2C-08 Comisiones de la plataforma por transacción (liquidación vía `settlement`).
- `[ ]` EC-C2C-09 Anti-fraude básico (límites, verificación, reportes de listing).

## Transversal (todas las fases)

- `[ ]` EC-X-01 OMS unificado: una sola orden comercial alimenta el pipeline logístico (shipment →
  planning → execution → tracking) sin duplicar lógica.
- `[ ]` EC-X-02 Panel unificado de comercio en el admin (Filament, ops-first): órdenes, pagos,
  catálogo, promociones, disputas.
- `[ ]` EC-X-03 Seguridad: PII de clientes (retención/borrado), PCI-scope mínimo (tokenización de pago,
  nunca guardar tarjetas), rate-limit del storefront, protección anti-bot/anti-scraping.
- `[ ]` EC-X-04 Performance a escala: catálogo y búsqueda cacheados, páginas de producto estáticas/ISR,
  colas para procesos pesados; probado bajo carga.
- `[ ]` EC-X-05 Analítica de comercio (GMV, conversión, cohortes) sin servicios de pago (self-host).
- `[ ]` EC-X-06 Contrato `openapi/v1.yaml` extendido (puerta nueva `/api/v1/store/*` o `/channels/*`).
- `[ ]` EC-X-07 Pruebas E2E del circuito comprar→pagar→enviar→entregar→(devolver) por los 3 modelos.

---

## Reutilización del backend existente (ya construido, NO re-hacer)

| Necesidad del comercio | Contexto existente que se reutiliza |
| --- | --- |
| Stock / disponibilidad (ATP) | `inventory` (con `Ownership` OWNED/FBD/DROPSHIP) |
| Dueño del producto / sellers | `sellers`, `sellerportal` |
| Entrega / última milla / ruteo | `shipments`, `planning`, `execution`, `tracking`, `network`, `coverage` |
| Promesa de entrega / slots | `promise`, calendario de despacho |
| Recepción de proveedores | `inbound` |
| Devoluciones (logística inversa) | `returns` |
| Liquidación a sellers / comisiones | `settlement` |
| Notificaciones | `notifications` (FCM→Telegram→Email→SMS) |
| Identidad / permisos / tokens | `access` |
| Núcleo DDD/CQRS/outbox | `shared`, `outbox`, `settings` |

Lo **nuevo** de este roadmap es aguas arriba de la operación: catálogo, carrito, checkout, pagos,
cuentas B2B, mercado C2C y el storefront. Todo lo de aguas abajo (operación logística) **ya existe**.
