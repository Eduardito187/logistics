# commerce/promotions

Motor de promociones y cupones del frente comercial (ADR 018, ítem EC-F0-09). Bounded context de la
familia `packages/commerce/*` (namespace `Commerce\Promotions\`), construido con la arquitectura
objetivo del proyecto: DDD + CQRS + Clean Architecture, UUID v7, eventos al outbox, reutilizando el
núcleo `logistics/shared` y `logistics/outbox`.

## Responsabilidad

- **Promoción** (agregado `Promotion`): nombre, código (null = promoción automática, no-null = cupón),
  tipo (porcentaje / monto fijo / envío gratis), valor, moneda, condiciones de aplicación (subtotal
  mínimo, canal, ventana de fechas), estado (draft/active/expired) y **versión** de reglas.
- **Evaluación de descuentos**: dado un subtotal, un canal y (opcional) un código de cupón, calcula el
  descuento aplicable sumando las promociones automáticas activas + la del cupón, con tope al subtotal.
- El cálculo del descuento es **dominio puro** (`Promotion::discountFor()`): no depende de Laravel.

## No-responsabilidad

- **Carrito / orden / checkout**: contextos aparte (`cart`, `orders`). El ahorro de envío de una promo
  `FreeShipping` lo materializa checkout; acá solo se expone `isFreeShipping()`.
- **Precio base e impuestos**: los resuelve `commerce/pricing`. La promoción solo descuenta sobre un
  subtotal ya calculado.
- **Facturación / contabilidad del descuento**: aguas abajo (SAP vía outbox, ADR 002).

## CQRS

- Comandos: `CreatePromotion`, `ActivatePromotion`, `ExpirePromotion`, `ChangePromotionRules`.
- Consultas: `EvaluatePromotions` (subtotal + canal + cupón → `DiscountQuote`), `GetPromotion`,
  `ListPromotions` (filtro por estado) → ReadModels primitivos.

## Estado

Estructura y dominio completos; tests Unit (dominio, casos numéricos) + Feature (contra Postgres).
Verificación de la DoD (PHPStan 8 + Pint + Pest + build) corre en CI.
