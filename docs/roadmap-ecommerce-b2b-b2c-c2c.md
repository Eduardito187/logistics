# Roadmap — Ecommerce B2B / B2C / C2C

> **Estado: 100% PENDIENTE — no se avanzó NADA de este roadmap.** Todos los ítems están en `[ ]`.
> Fecha de alta: 2026-10-29. Es una **iniciativa nueva**, separada del backend logístico ya construido.

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

- `[ ]` EC-F0-01 **ADR**: decidir si el comercio es un dominio nuevo (`packages/commerce/*`) o extiende
  `logistics/*`; límites con `sellers`, `inventory`, `shipments`, `access`. (Escribir ADR 018.)
- `[ ]` EC-F0-02 **Catálogo / PIM**: contexto `catalog` — producto, variantes, atributos, categorías,
  medios (imágenes), estados (publicado/borrador), SKU canónico. Reutiliza `inventory` para stock/ATP.
- `[ ]` EC-F0-03 **Pricing**: listas de precio, moneda (BOB), impuestos (IVA/IT Bolivia), redondeo,
  precios por canal/segmento (base para B2B price lists y B2C retail).
- `[ ]` EC-F0-04 **Cuentas/identidad comercial**: extender `access` con tipos de cuenta (persona,
  empresa, seller-persona), direcciones, perfiles; SSO/login para storefront (separado del admin).
- `[ ]` EC-F0-05 **Carrito**: contexto `cart` — carrito por sesión/usuario, líneas, reglas de
  disponibilidad (ATP de `inventory`), persistencia, merge invitado→logueado.
- `[ ]` EC-F0-06 **Checkout + Order (OMS)**: contexto `orders` — orden de compra comercial, estados
  (pending/paid/fulfilling/…); **puente al backend logístico**: una orden pagada **crea un Shipment**
  (reusa `shipments`/`planning`/`execution`). Idempotente por orden.
- `[ ]` EC-F0-07 **Pagos**: contexto `payments` detrás de contrato (`PaymentGateway`), con adaptador
  stub + al menos una pasarela real por env (QR interoperable BCB / transferencia / tarjeta vía proveedor).
  Webhooks de confirmación → evento `payments.captured` → outbox → `orders`.
- `[ ]` EC-F0-08 **Búsqueda y navegación**: búsqueda de catálogo (Postgres FTS o Meilisearch self-host),
  filtros/facetas, orden, paginación con cursores.
- `[ ]` EC-F0-09 **Promociones/cupones**: motor de reglas (descuentos, cupones, envíos gratis) versionado.
- `[ ]` EC-F0-10 **Impuestos y facturación**: cálculo de impuestos y emisión de documento fiscal
  (factura) — integración con el sistema tributario (adaptador).
- `[ ]` EC-F0-11 **Notificaciones comerciales**: reutilizar `notifications` (confirmación de orden, pago,
  envío, entrega) con plantillas nuevas.
- `[ ]` EC-F0-12 **Storefront (SPA)**: frontend de tienda (Vue 3 + Inertia, coherente con `/app`) —
  catálogo, ficha de producto, carrito, checkout, cuenta/pedidos, tracking público ya existente.

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
