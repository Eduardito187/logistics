# commerce/catalog

Catálogo / PIM del frente comercial (ADR 018, ítem EC-F0-02). Primer bounded context de la familia
`packages/commerce/*` (namespace `Commerce\Catalog\`), construido con la arquitectura objetivo del
proyecto: DDD + CQRS + Clean Architecture, UUID v7, eventos al outbox, reutilizando el núcleo
`logistics/shared` y `logistics/outbox`.

## Responsabilidad

- **Producto** (agregado `Product`): nombre, descripción, SKU canónico, estado (draft/published/archived),
  variantes y medios (value objects persistidos como JSONB en la fila del producto).
- **Categoría** (agregado `Category`): árbol simple con slug.
- El **SKU canónico** es el puente 1:1 con `inventory` (ATP). El **dueño** del producto (seller) se
  referencia por UUID (`seller_id`; null = producto propio OWNED de Dismac, ADR 017).

## Medidas físicas (Roadmap 02, Fase 2.0)

- VO `PhysicalSpec` (peso kg, L/A/H cm; parcial permitido; volumen m³ solo con las tres dimensiones).
- Fuente maestra: admin + Magento/integraciones por SKU (`PUT /integrations/catalog/products/{sku}/physical-spec`,
  `POST /integrations/catalog/products/physical-specs`). SAP no es fuente. Evento `catalog.product_physical_spec_changed`.
- La categoría guarda medidas por defecto (cascada producto → categoría → default global en shipments).

## No-responsabilidad

- **Precio e impuestos**: los resuelve el contexto `commerce/pricing` (ADR 018). El catálogo no guarda precio.
- **Stock/disponibilidad**: lo resuelve `inventory` por un puerto de lectura (se cablea en la fase de carrito).
- **Carrito/orden/pago**: contextos aparte (`cart`, `orders`, `payments`).

## CQRS

- Comandos: `DraftProduct`, `PublishProduct`, `ArchiveProduct`, `AddProductVariant`, `CreateCategory`,
  `SetProductPhysicalSpec` (por id o SKU), `SetCategoryPhysicalDefaults`.
- Consultas: `GetProduct`, `GetProductBySku`, `ListProducts` (filtro por estado), `ListCategories` → ReadModels primitivos.

## Estado

Estructura y dominio completos; tests Unit (dominio) + Feature (contra Postgres). Verificación de la DoD
(PHPStan 8 + Pint + Pest + build) corre en CI.
