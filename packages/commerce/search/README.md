# commerce/search

Búsqueda y navegación de catálogo del frente comercial (ADR 018, ítem EC-F0-08). Bounded context de la
familia `packages/commerce/*` (namespace `Commerce\Search\`), construido con la arquitectura objetivo del
proyecto: DDD + CQRS + Clean Architecture, reutilizando el núcleo `logistics/shared`.

Es un contexto **100% de lectura** (read-side CQRS): **no tiene agregado de escritura propio** ni eventos
de dominio. Proyecta y consulta el catálogo que es dueño el contexto `commerce/catalog`.

## Responsabilidad

- **Búsqueda de productos** (`SearchProducts`): texto libre sobre nombre + descripción con **Postgres
  Full-Text Search** (`to_tsvector('spanish', …) @@ plainto_tsquery('spanish', ?)`), filtros por estado
  y categoría, orden estable por `(name, id)` y **paginación por cursor** (keyset, no offset) para barrer
  millones de filas sin degradar.
- **Facetas por categoría** (`ListFacets`): cuenta de productos por `category_id` para la navegación.

## No-responsabilidad

- **Dueño del catálogo**: el producto lo crean/publican/archivan los comandos de `commerce/catalog`.
  Search NO escribe nada en `commerce_products`; solo la lee.
- **Precio, stock, carrito, orden**: contextos aparte (`pricing`, `inventory`, `cart`, `orders`).

## Integración cross-context (sin acople — patrón del skill)

Search lee la tabla `commerce_products` de `catalog` en **modo read-only** con `DB::table('commerce_products')`
envuelto en `try/catch`: si la tabla aún no existe (catalog no desplegado) degrada a resultado vacío en
vez de romper. **No** depende de `catalog` por composer ni hace `use` de sus clases. Así cada contexto
sigue siendo extraíble a microservicio.

## CQRS

- Consultas: `SearchProducts` (term, categoryId, status, limit, cursor) → `SearchPage`;
  `ListFacets` (status) → `list<CategoryFacet>`. Ambas devuelven ReadModels primitivos (`toArray()`).
- El criterio de búsqueda se valida en el VO de dominio `SearchCriteria` (`final readonly`, limit 1..100,
  estado permitido). Es PHP puro: sin Laravel ni Eloquent.

## Índice de escala (FTS)

La migración `2026_10_30_000100_create_commerce_products_fts_index` crea, **aditiva e idempotente**
(`CREATE INDEX IF NOT EXISTS … USING gin (…)`), un índice GIN funcional sobre
`to_tsvector('spanish', coalesce(name,'') || ' ' || coalesce(description,''))` para acelerar la FTS sobre
`commerce_products`. Toca una tabla de **otro contexto** (catalog), por eso lleva un prefijo de fecha
**posterior** a las migraciones de catalog (`2026_10_29_0002*`) y es idempotente en `up()`/`down()`. Si se
prefiere no tocar la tabla de catalog, puede omitirse y la FTS funciona igual (sin el índice, más lenta).

## Estado

Estructura, VO de dominio, queries, reader de lectura y Presentation HTTP completos; tests Unit (VO) +
Feature (FTS + cursor contra Postgres). Verificación de la DoD (PHPStan 8 + Pint + Pest) corre en CI.
