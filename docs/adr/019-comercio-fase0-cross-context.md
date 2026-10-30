# ADR 019 — Comercio Fase 0: decisiones cross-context (precio/promos, ATP, cuentas, checkout)

**Status:** Accepted
**Fecha:** 2026-10-30
**Contexto:** cierre de la Fase 0 del frente comercial (ADR 018). Define cómo los contextos
`commerce/*` se relacionan entre sí y con la logística **sin romper la autonomía** (cada contexto
extraíble a microservicio, ADR 001/008).

## Contexto

Los contextos de comercio (catalog, cart, orders, payments, pricing, promotions, accounts, invoicing,
search) ya tienen Domain/Application/API. Para completar el flujo de compra hay interacciones entre
contextos que, mal resueltas, acoplarían dominios. Se decide el patrón de cada una.

## Decisiones

### 1. Precio y promociones: resolución **client-side** (menor acople)
El **storefront** resuelve el precio (`GET /channels/pricing/price` por SKU + canal, con IVA/IT) y evalúa
las promociones (`POST /channels/promotions/evaluate`) **antes** de agregar al carrito / hacer checkout, y
manda el **precio ya congelado** en centavos. El carrito y la orden NO consultan `pricing`/`promotions`
server-side.
- **Por qué:** evita que `cart`/`orders` dependan de `pricing`/`promotions`; mantiene los contextos
  autónomos. El precio congelado en la línea es, además, el comportamiento correcto de un pedido
  (snapshot del momento de compra).
- **Consecuencia:** la correcta aplicación de precio/promos es responsabilidad del canal (ver
  `docs/storefront-magento-brief.md`). Si en el futuro se quiere forzar server-side, se agrega un puerto
  `PriceResolver`/`PromotionEvaluator` en `cart` (implementado en Infrastructure contra pricing/promotions)
  sin cambiar el dominio.

### 2. Disponibilidad (ATP) catálogo ↔ inventory: **read port**
`catalog` define un contrato de Application `ProductAvailabilityReader` e implementa en Infrastructure una
**lectura cruzada read-only** del stock de `inventory` por `canonical_sku` (query builder, sin el Eloquent
ni el dominio de inventory; degrada a 0 si la tabla no existe). Se expone en
`GET /channels/catalog/products/{id}/availability`.
- **Por qué:** mismo patrón que las lecturas cruzadas de logística (p. ej. `DbStopEnrichmentReader`): el
  consumidor define el puerto, la Infra lo implementa; no se acopla el dominio del otro contexto.

### 3. Cuentas ↔ access/identidad: **solo referencia**
La cuenta comercial (`accounts`) guarda `user_id` como **referencia** al usuario de `access` (identidad y
RBAC). NO hay provisioning automático (registrar cuenta NO crea el user): el user ya existe en `access` y
la cuenta comercial lo referencia.
- **Por qué:** `access` es el dueño de la identidad; duplicar/crear usuarios desde comercio rompería esa
  responsabilidad. Si se necesita alta conjunta, se hace orquestando ambos comandos en la capa de
  aplicación del canal, no acoplando los contextos.

### 4. Checkout (cart → order) y conversión del carrito: **desacoplado por outbox**
El checkout (`POST /channels/checkout`) crea la orden a partir del carrito (lee un **snapshot** del
carrito por un read port en `orders`). La **conversión** del carrito a "convertido" NO la hace `orders`
de forma síncrona: la orden emite `orders.order_placed` (con el `cart_id`), y un dispatcher en `cart`
(`OrderPlacedCartConverter`, idempotente) despacha `MarkCartConverted` vía outbox.
- **Por qué:** `orders` no depende de `cart`; la reacción viaja por el outbox transaccional (at-least-once,
  idempotente), igual que los puentes de marketplace (`inbound.goods_received` → inventory).

### 5. Puentes de fulfillment y facturación (ya vigentes, ADR 018)
`payments.captured` → orden pagada; `orders.order_paid` → (fanout) crea el envío en logística
(`ReceiveShipment`) + emite la factura (`IssueInvoiceForOrder`). Las **notificaciones** al cliente
(confirmación/pago) se suman como reactores de esos mismos eventos por el outbox.

## Consecuencias

- Los contextos de comercio permanecen autónomos; las interacciones son por **puertos de lectura** o por
  **eventos de outbox**, nunca por dependencias de dominio cruzadas.
- La facturación **fiscal real** (SIN Bolivia: facturación electrónica, CUF/CUFD, firma) queda como
  **pendiente externo** (requiere credenciales/integración con el SIN); el documento interno sí se genera.
- La búsqueda usa **PostgreSQL FTS** (self-host, sin servicios externos), no Meilisearch.
