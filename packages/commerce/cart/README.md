# commerce/cart

Carrito del frente comercial (ADR 018, ítem EC-F0-05). Bounded context de la familia
`packages/commerce/*` (namespace `Commerce\Cart\`), construido con la arquitectura objetivo del
proyecto: DDD + CQRS + Clean Architecture, UUID v7, eventos al outbox, reutilizando el núcleo
`logistics/shared` y `logistics/outbox`.

## Responsabilidad

- **Carrito** (agregado `Cart`): dueño opcional (`customer_id`; null = invitado identificado por
  `session_id`), estado (open/converted/abandoned), moneda y líneas.
- **Línea** (value object `CartLine`): SKU canónico, nombre del producto, precio unitario en centavos
  y cantidad. Las líneas se persisten como JSONB en la fila del carrito (son VOs del agregado, no
  entidades consultables aparte).
- **Fusión** invitado → cliente (`mergeFrom`) sumando cantidades por SKU, y **conversión** del carrito
  a orden (`markConverted`), que emite el hecho que `orders` consumirá vía outbox.

## No-responsabilidad

- **Precio**: lo resuelve `commerce/pricing` aguas arriba; el precio unitario viaja YA resuelto dentro
  del comando `AddItem`. El carrito no llama a pricing (sin dependencia cruzada).
- **Disponibilidad/stock (ATP)**: se consulta por el puerto `InventoryAvailabilityPort`. Hoy el
  adaptador `AlwaysAvailableInventory` es un stub (devuelve true); se cablea a `inventory`/ATP en una
  fase posterior.
- **Orden/pago**: contextos aparte (`orders`, `payments`). El carrito solo publica `cart.converted`.

## CQRS

- Comandos: `OpenCart`, `AddItem`, `UpdateItemQty`, `RemoveItem`, `ClearCart`, `MergeGuestCart`,
  `MarkCartConverted`.
- Consultas: `GetCart` (por id), `GetCartBySession` → `CartView` (primitivo, con `total_cents`).

## Estado

Estructura y dominio completos; tests Unit (dominio) + Feature (contra Postgres). Verificación de la DoD
(PHPStan 8 + Pint + Pest + build) corre en CI.
