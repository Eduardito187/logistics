# Brief para el agente de Magento — Storefront ↔ plataforma DisTrack (comercio + logística)

> **Para quién es este documento:** el agente/equipo de **Magento** (Dismatec) que construirá el
> **storefront** (tienda online para el cliente) y su integración con la plataforma **DisTrack**
> (este backend de comercio + logística).
>
> **Cómo usarlo:** es un brief autocontenido. No necesitás acceso a este repo: el contrato de la API va
> embebido más abajo. La fuente de verdad formal es `openapi/v1.yaml` (OpenAPI 3.1) de DisTrack; si lo
> tenés a mano, generá el cliente desde ahí. Pediles a los responsables de DisTrack el `openapi/v1.yaml`
> y la credencial de canal (`X-Channel-Key`) para tu entorno.

---

## 1. Contexto en una página

**DisTrack** es la plataforma propia de Dismac para **comercio** (catálogo, carrito, órdenes, pagos,
pricing, promociones — contextos `commerce/*`, ADR 018) y **logística** (planificación, flota, última
milla, tracking, almacén). Expone una API REST versionada (`/api/v1`) con **tres puertas**:

| Puerta | Para quién | Credencial |
|---|---|---|
| `/api/v1/app/*` | app interna Flutter (chofer, preparador, tienda, supervisor) | token por usuario+dispositivo (Sanctum) |
| **`/api/v1/channels/*`** | **app de clientes, web (storefront), call center** | **credencial por canal (`X-Channel-Key`)** |
| `/api/v1/integrations/*` | SAP, Magento, transportistas, webhooks | credencial de servicio (`X-Service-Credential`) |

El **storefront es un "canal"** → consume `/api/v1/channels/*`. Cuando se confirma el pago, DisTrack
**crea automáticamente el envío** (logística) y **emite la factura** de forma asíncrona (no lo hace el
storefront).

---

## 2. LA decisión de arquitectura (leer antes de codear)

Magento ya es una plataforma de comercio completa (catálogo, carrito, checkout, promos, pagos). Hay dos
modelos posibles de integración; elegí UNO con el dueño del proyecto:

- **Modelo A — Magento como storefront "delgado" sobre el comercio de DisTrack.** El UI de Magento
  consume la **API de comercio de DisTrack** (catálogo/carrito/checkout de la puerta `channels`,
  sección 4). DisTrack es la fuente de verdad del catálogo, carrito y órdenes. Útil si se quiere UN solo
  motor de comercio (DisTrack) y Magento solo como capa de presentación.

- **Modelo B — Magento como motor de comercio; DisTrack como fulfillment (RECOMENDADO si Magento ya
  opera).** Magento maneja catálogo/carrito/checkout/pagos con SU propio backend, y al confirmarse una
  orden la **envía a DisTrack para la entrega** (puerta `integrations`, sección 5) y **muestra el
  tracking**. Evita duplicar el motor de comercio. Es el modelo histórico de Dismac (las fuentes de
  pedidos incluyen "Magento actual").

> Recomendación: si Magento ya es el e-commerce productivo, **Modelo B** (no reimplementar carrito/checkout
> contra DisTrack). Reservá la API de comercio de DisTrack (Modelo A) para canales que Magento NO sirve
> (call center, portal B2B a medida, marketplace C2C). Igual dejo **ambos contratos** abajo.

---

## 3. Convenciones comunes del API (ambos modelos)

- **Base URL:** `http://localhost:8110/api/v1` (desarrollo). El host de staging/prod lo provee DisTrack.
- **Formato:** JSON. `Content-Type: application/json`, `Accept: application/json`.
- **Auth de la puerta channels:** cabecera `X-Channel-Key: <credencial del canal>` en TODA request a
  `/channels/*`. Sin ella → 401.
- **Idempotencia (escrituras):** mandá `Idempotency-Key: <uuid>` en POST/PATCH/DELETE. DisTrack deduplica
  reintentos (clave inocua si no se envía). Úsala en checkout y en operaciones de carrito para que un
  reintento de red no duplique.
- **Envelope de respuesta:**
  - Éxito con recurso: `{ "data": { ... } }` · listas: `{ "data": [ ... ], "meta": { ... } }`.
  - Acuse de escritura async: `{ "data": { "accepted": true, ... } }` (HTTP 202).
  - Error de regla de negocio / no encontrado: `{ "error": { "code": "...", "message": "...", "details"?: {...} } }`.
  - Error de validación de formulario (422): `{ "message": "...", "errors": { "campo": ["..."] } }` (formato Laravel).
- **Paginación (listados, opcional y aditiva — "E-02"):** parámetros `per_page` (1..200, def. 50) + `page`
  (modo offset) **o** `cursor` (token opaco, modo cursor). Si no mandás ninguno, viene la colección
  completa **sin** `meta`.
  - `meta` offset: `{ count, page, per_page, total, total_pages, next_page|null, prev_page|null }`.
  - `meta` cursor: `{ count, per_page, has_more, next_cursor|null }`.

---

## 4. Contrato A — API de comercio (puerta `channels`)

Todos bajo `/api/v1/channels`. Auth: `X-Channel-Key`. Los `id` son UUID.

### 4.1 Catálogo (lectura)

**`GET /channels/catalog/products`** — lista de productos.
Query (opcionales): `status` ∈ {draft,published,archived}; `per_page`/`page`/`cursor` (paginación).
`200`: `{ "data": [ <Product> ], "meta"?: {...} }`.

**`GET /channels/catalog/products/{id}`** — un producto. `200`: `{ "data": <Product> }` · `404`: ErrorEnvelope.

**`GET /channels/catalog/categories`** — categorías. `200`: `{ "data": [ <Category> ] }`.

```jsonc
// <Product>
{
  "id": "uuid", "seller_id": "uuid|null", "category_id": "uuid|null",
  "name": "Taladro percutor 650W", "description": "string|null",
  "canonical_sku": "DRL-650", "status": "published",   // draft|published|archived
  "variants": [ { "sku": "DRL-650-RED", "name": "Rojo", "attributes": { "color": "rojo" } } ],
  "media":    [ { "path": "catalog/drl-650/front.jpg", "alt": "Vista frontal", "position": 0 } ]
}
// <Category>
{ "id": "uuid", "name": "Herramientas eléctricas", "slug": "herramientas-electricas", "parent_id": "uuid|null" }
```

### 4.2 Carrito (lectura + escritura; cada escritura devuelve el carrito actualizado)

- **`POST /channels/cart`** → abre carrito. Body: `{ "session_id": "sess_abc", "customer_id"?: "uuid|null", "currency"?: "BOB" }`. `201`: `{ "data": <Cart> }`.
- **`GET /channels/cart/{cart}`** → `200`: `{ "data": <Cart> }` · `404`.
- **`POST /channels/cart/{cart}/items`** → agrega línea. Body: `{ "sku": "...", "product_name": "...", "unit_price_cents": 12990, "quantity"?: 1 }`. `200`: `{ "data": <Cart> }`.
- **`PATCH /channels/cart/{cart}/items/{sku}`** → cambia cantidad (0 = quitar). Body: `{ "quantity": 3 }`. `200`.
- **`DELETE /channels/cart/{cart}/items/{sku}`** → quita la línea. `200`.

```jsonc
// <Cart>
{
  "id": "uuid", "customer_id": "uuid|null", "session_id": "sess_abc",
  "status": "open", "currency": "BOB",
  "lines": [ { "sku": "SKU-1", "product_name": "Taladro X", "unit_price_cents": 12990, "qty": 2, "subtotal_cents": 25980 } ],
  "total_cents": 25980
}
```
> **Importante:** el carrito recibe `unit_price_cents` **ya resuelto por el storefront** (DisTrack no
> consulta precios en el carrito todavía). El storefront debe resolver precio/promos (con su propio motor
> o con los contextos `pricing`/`promotions` de DisTrack) **antes** de agregar el ítem.

### 4.3 Checkout + orden

- **`POST /channels/orders`** — checkout. Body:
```jsonc
{
  "customer_id": "uuid|null",          // opcional
  "currency": "BOB",                   // opcional, ISO-4217 (def. BOB)
  "ship_to": {                         // requerido
    "customer_name": "Juan Pérez", "address_text": "Av. Siempre Viva 123, Santa Cruz",
    "reference": "Portón azul|null", "phone": "+59170000000|null", "email": "juan@example.com|null"
  },
  "lines": [ { "sku": "SKU-123", "product_name": "...", "unit_price_cents": 45000, "quantity": 2 } ]  // min 1
}
```
  **Respuesta `202`:** `{ "data": { "accepted": true, "customer_name": "...", "currency": "BOB" } }`.
  ⚠️ **No devuelve el `order_id`** (ver gotcha en §7). El checkout es **asíncrono**.

- **`GET /channels/orders/{order}`** — una orden por id. `200`: `{ "data": <Order> }` · `404`.
```jsonc
// <Order>
{
  "id": "uuid", "customer_id": "uuid|null", "status": "pending",   // pending|paid|fulfilling|cancelled...
  "currency": "BOB", "total_cents": 90000, "shipment_requested": false,
  "ship_to": { "customer_name": "...", "address_text": "...", "reference": "...|null", "phone": "...|null", "email": "...|null" },
  "lines": [ { "sku": "SKU-123", "product_name": "...", "unit_price_cents": 45000, "quantity": 2 } ]
}
```

### 4.4 Pago (puerta `integrations`, lo invoca la pasarela, no el storefront)
El cobro lo confirma la pasarela de pago vía webhook a DisTrack (`POST /integrations/payments/webhook`,
credencial de servicio). Al capturarse el pago, DisTrack dispara en cadena (outbox): **orden pagada →
crea el envío + emite la factura**. El storefront solo refleja el estado consultando la orden.

---

## 5. Contrato B — Ingesta de pedidos para fulfillment (puerta `integrations`)

Si Magento es el motor de comercio (Modelo B), al confirmarse una orden en Magento se la **envía a
DisTrack para la entrega**:

- **`POST /integrations/shipments`** — ingesta de un pedido (operationId `receiveShipment`). Es
  **idempotente por `(source, external_id)`**: reenviar el mismo pedido no crea un envío nuevo (responde
  `202`). `source` = "magento"; `external_id` = el id de la orden en Magento. Para la forma EXACTA del
  body, usá `openapi/v1.yaml` (operationId `receiveShipment`) — incluye destinatario, dirección/coordenada,
  líneas (producto + cantidad), y datos de contacto.
- **`POST /integrations/shipments/import`** — carga masiva por planilla (CSV), idempotente por fila.
- **Tracking al cliente:** DisTrack ya tiene **página pública de seguimiento** por envío + estados en
  vivo (GPS). Magento puede enlazar/empotrar esa URL de tracking para que el cliente vea su pedido.
- Auth: `X-Service-Credential` (credencial de servicio, la provee DisTrack).

> En este modelo, Magento NO usa el catálogo/carrito/checkout de DisTrack (§4): usa los suyos. Solo manda
> la orden confirmada para fulfillment y muestra el tracking.

---

## 6. Flujo end-to-end (qué pasa aguas abajo, para ambos modelos)

1. Cliente arma el carrito y hace checkout (en el storefront).
2. Se confirma el pago (pasarela → webhook a DisTrack, o el flujo de Magento).
3. DisTrack: **pago capturado → orden pagada → (a) crea el envío en logística y lo pasa a *fulfilling*,
   (b) emite la factura**. Todo asíncrono e idempotente (outbox transaccional).
4. Logística planifica, rutea y entrega; el cliente ve el **tracking** (página pública / app).

---

## 7. Gotchas y límites conocidos (importante para planificar)

1. **Checkout no devuelve `order_id` (202).** Hoy `POST /channels/orders` responde un acuse sin el id de
   la orden (el id se genera server-side y el comando es asíncrono). ⇒ El storefront **no puede mostrar la
   confirmación "tu orden #X"** consultando por id inmediatamente. **Pendiente de DisTrack:** devolver el
   id por una **referencia de cliente / idempotencia** (p. ej. aceptar un `client_order_ref` y exponer
   `GET /channels/orders?client_ref=...`). **Coordiná esto con DisTrack antes de construir la pantalla de
   confirmación** si vas por el Modelo A.
2. **Precio/promos en el carrito (Modelo A):** el carrito de DisTrack recibe el precio ya resuelto; no
   aplica pricing/promos server-side todavía. El storefront debe resolverlos antes de agregar.
3. **Paginación:** si no mandás `per_page`/`page`/`cursor`, viene TODO sin `meta`. Para catálogos grandes,
   paginá siempre.
4. **Idempotencia:** usá `Idempotency-Key` en escrituras (checkout sobre todo) para no duplicar ante
   reintentos.
5. **Marca y rendimiento del storefront** (si aplica a Dismac): branding DisTrack, textos al cliente en su
   idioma, y la política de **CERO CDN en runtime** que rige el frente de Dismac (todo self-host) — confirmá
   con el equipo si aplica al storefront Magento.
6. **Estados de orden** (`status`): pending → paid → fulfilling → (entregado/cancelado). Mapealos a los
   estados que muestres al cliente.

---

## 8. Entregable esperado del agente de Magento

1. Decidir **Modelo A vs B** con el dueño (sección 2) y dejarlo documentado.
2. **Storefront** (UI de tienda): listado + detalle de producto, carrito, checkout (con `ship_to`),
   confirmación y seguimiento del pedido.
3. La **integración** elegida:
   - Modelo A: cliente HTTP contra `/channels/*` con `X-Channel-Key`, siguiendo §4 (y resolviendo el
     gotcha del `order_id`).
   - Modelo B: envío de órdenes confirmadas a `POST /integrations/shipments` (idempotente por
     `source=magento` + `external_id`) y enlace al tracking (§5).
4. Manejo de **errores** (envelope `error` / `422` de validación) y de **idempotencia**.
5. Config por entorno (base URL + credencial) sin hardcodear secretos.

---

## 9. Referencias
- **`openapi/v1.yaml`** (DisTrack) — contrato formal OpenAPI 3.1 y fuente de verdad. OperationIds de
  comercio: `listCatalogProducts`, `getCatalogProduct`, `listCatalogCategories`, `openCart`, `getCart`,
  `addCartItem`, `updateCartItemQty`, `removeCartItem`, `placeOrder`, `getOrder`. Fulfillment:
  `receiveShipment`, `importShipments`.
- Pedir a DisTrack: host de staging/prod, `X-Channel-Key` del canal storefront, y (Modelo B)
  `X-Service-Credential` + la forma exacta de `receiveShipment`.
