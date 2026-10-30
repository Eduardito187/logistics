# ADR 018 — Comercio como familia de contextos separada (`packages/commerce/*`)

**Status:** Accepted
**Fecha:** 2026-10-29
**Autores:** @eduard
**Deciders:** @eduard (dueño del proyecto)

## Contexto

La plataforma `logistics` ya resuelve de punta a punta la **operación**: fulfillment, última milla,
flota, ruteo, tracking, recepción de proveedores (`inbound`), inventario con propiedad
(OWNED/FBD/DROPSHIP, ADR 017), devoluciones, liquidación a sellers y notificaciones. Todo eso es
**aguas abajo** de una venta: asume que la unidad **ya existe** y hay que moverla.

Lo que falta —y lo que planifica [`docs/roadmap-ecommerce-b2b-b2c-c2c.md`](../roadmap-ecommerce-b2b-b2c-c2c.md)—
es el **frente comercial** (aguas arriba): catálogo/PIM, precios e impuestos, carrito, checkout,
órdenes (OMS), pagos, búsqueda, promociones, facturación y el storefront, en tres modelos de negocio
(B2C, B2B, C2C). Es un **subdominio distinto** del de operaciones logísticas: otro lenguaje ubicuo
(producto publicable, precio por canal, orden de compra, carrito, pasarela de pago), otro ritmo de
cambio y otra escala de lectura (catálogo/búsqueda cacheados y servidos a millones de visitas).

Fuerza clave (igual que en ADR 017, pero en el otro extremo del flujo): existe **un único punto de
convergencia** entre comercio y logística — **una orden pagada**. A partir de ahí todo es operación
que ya existe. Antes de ese punto, nada de comercio tiene por qué vivir dentro de los contextos
logísticos.

Decisión a tomar: ¿el comercio **extiende** `packages/logistics/*` o es una **familia de contextos
nueva**? ¿Dónde quedan los límites con `sellers`, `inventory`, `shipments` y `access`?

## Decisión

El comercio se construye como una **familia de bounded contexts nueva y separada** bajo
`packages/commerce/*`, namespace `Commerce\<Context>\`, **reutilizando el núcleo `logistics/shared`**
(Uuid v7, `AggregateRoot`, value objects, `DomainEvent`, `CommandBus`/`QueryBus`, outbox transaccional,
`Clock`). Misma arquitectura objetivo que el resto (ADR 001/008): DDD + CQRS + Clean Architecture,
cada contexto autónomo y **extraíble a microservicio** cuando el volumen lo pida.

Contextos de comercio (se crean por fase, no todos de golpe):
`catalog`, `pricing`, `cart`, `orders`, `payments`, `search`, `promotions`, `tax`, `accounts`.

### Por qué `packages/commerce/*` y no `packages/logistics/*`

- Es un **subdominio distinto** (comercio ≠ operación logística). Mezclarlos erosiona el lenguaje
  ubicuo y el aislamiento que da Clean Architecture.
- Mantiene **retail/operación 100% intactos**: el backend logístico no cambia su contrato ni su
  dominio por el comercio; solo recibe un evento de integración cuando hay una orden pagada.
- `shared` es **infraestructura transversal pura** (no es un contexto de negocio logístico), así que
  es legítimo y deseable que comercio dependa de `Logistics\Shared\*`. NO se duplica el núcleo.

### Regla de integración comercio ↔ logística (dura)

Un contexto de comercio **nunca** llama al Domain/Infrastructure de un contexto logístico (ni al
revés). Toda relación cruza por **puertos** (contratos en `Domain/Contract`, implementados en
`Infrastructure` como *anti-corruption layer*) y por **eventos de integración vía outbox**:

- **`orders` → logística (puente OMS→Shipment):** al confirmarse el pago, `orders` emite el evento de
  integración `commerce.order.paid` al outbox. Un *handler* de integración (adaptador en
  Infrastructure) crea el `Shipment` reutilizando el Application de `shipments`/`planning`. El puente
  es **idempotente por `order_id`** (una orden crea a lo sumo un shipment; reintentos del outbox no
  duplican). `orders` **no conoce** `shipments`; solo publica el hecho de negocio.
- **`catalog`/`cart` → `inventory` (ATP):** la disponibilidad (available-to-promise) se lee por un
  **puerto de consulta** (read model de `inventory`), nunca escribiendo stock desde comercio. El SKU
  canónico de `catalog` mapea 1:1 al SKU de `inventory`.
- **`catalog` → `sellers`:** el producto referencia al dueño por `Uuid` (`seller_id` / `owner_party_id`),
  interpretado vía un puerto ACL contra `sellers`. Comercio no duplica los datos del seller.
- **`accounts` → `access`:** `access` sigue siendo la autoridad de **identidad/authN** (usuarios,
  tokens, SSO, MFA). El contexto `accounts` de comercio modela los conceptos **comerciales**
  (tipo de cuenta persona/empresa/seller-persona, direcciones de compra, organización B2B, centros de
  costo, roles de compra) y referencia al usuario de `access` por `Uuid`. Así la identidad de
  ops/admin (access) no se mezcla con la identidad de cliente del storefront (que escala distinto).

### Registro y frontera SAP

- Cada package nuevo se registra en **los dos lugares** (regla no negociable #3): `bootstrap/providers.php`
  y `extra.laravel.dont-discover` del `composer.json` raíz, **más** el repositorio `path`
  `packages/commerce/*` y el PSR-4 `Commerce\\` en el `autoload`/`autoload-dev` — en el mismo cambio.
- La frontera con SAP (ADR 002) **no cambia**: comercio no toca SAP directo. Si una venta debe
  reflejarse en SAP (factura, cobro), se hace **vía outbox** por el conector existente, nunca con HTTP
  síncrono en el request.
- Stack **100% open-source y gratuito** (ADR 013/014/015): pagos tras el contrato `PaymentGateway` con
  adaptadores open-source / locales; búsqueda con Postgres FTS (o Meilisearch self-host); sin servicios
  de pago.

### Storefront

El storefront (EC-F0-12) reutiliza el stack de **ADR 009** (Vue 3 + Inertia) en el mismo app Laravel,
como rutas/páginas aditivas separadas del panel admin (Filament) y del `/app` operativo. No es un
repo nuevo.

## Alternativas consideradas

### Alternativa A: extender `packages/logistics/*` con los contextos de comercio
- Pros: un solo árbol de packages; `shared` ya está ahí.
- Contras: mezcla dos subdominios con lenguajes ubicuos distintos; el backend logístico (estable y en
  producción) pasaría a cambiar por razones comerciales; erosiona el aislamiento y complica extraer
  comercio a su propio servicio/monorepo cuando la escala de lectura lo pida.

### Alternativa B: repositorio separado para comercio (dos backends)
- Pros: separación física total.
- Contras: hoy es prematuro (ADR 001: no partir a microservicios antes de tiempo); duplicaría el
  núcleo `shared`, el tooling (Docker/CI/phpstan/pint), el contrato OpenAPI y el deploy. El puente
  orden→shipment pasaría a ser red entre servicios sin necesidad todavía.

### Alternativa C (elegida): familia `packages/commerce/*` en el mismo monolito modular, reutilizando `shared`, integrada por outbox/puertos
- Pros: aditivo (operación intacta); un solo tooling/deploy/CI; cada contexto autónomo y extraíble;
  el puente comercio↔logística ya es asíncrono e idempotente (listo para separarse a servicio sin
  reescribir); reutiliza todo lo construido aguas abajo.
- Contras: más packages que gestionar; exige disciplina en los puertos/ACL y en no llamar cruzado
  entre familias.

## Consecuencias

### Positivas
- Habilita B2C/B2B/C2C reutilizando fulfillment/última milla/tracking/returns/settlement sin tocarlos.
- Mantiene el backend logístico estable: solo consume `commerce.order.paid` por outbox.
- Comercio extraíble a su propio servicio/monorepo cuando la escala lo pida (el límite ya es asíncrono).

### Negativas / trade-offs aceptados
- 9 packages nuevos a lo largo de las fases + el PSR-4/providers/dont-discover de cada uno.
- Disciplina obligatoria: nada de llamadas directas entre `commerce/*` y `logistics/*`; todo por
  puerto o evento de integración.

### Neutras
- El punto de convergencia (orden pagada → shipment) queda explícito y testeable de forma aislada.

## Referencias

- [`docs/roadmap-ecommerce-b2b-b2c-c2c.md`](../roadmap-ecommerce-b2b-b2c-c2c.md) — plan por fases (EC-F0-xx, B2C, B2B, C2C).
- ADR 001 (monolito modular), ADR 002 (fuera de SAP), ADR 008 (DDD/CQRS/UUIDv7/outbox), ADR 009
  (Vue+Inertia), ADR 017 (marketplace/propiedad), ADR 013/014/015 (open-source sin pago).
- Skill de proyecto `.claude/skills/bounded-context`.
