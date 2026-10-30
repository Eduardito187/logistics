# commerce/pricing

Bounded context de **precios e impuestos** del frente comercial (ADR 018, ítem EC-F0-03 del roadmap de
ecommerce). Familia `packages/commerce/*`, namespace `Commerce\Pricing\`, reutiliza el núcleo
`Logistics\Shared\*` y `Logistics\Outbox\*`. DDD + CQRS + Clean Architecture, UUID v7, outbox.

## Responsabilidad

- Modelar **listas de precios** (`PriceList`) por **canal** (Retail, B2B, C2C) y su ciclo de vida
  (Draft → Active → Archived).
- Fijar el **precio al público (IVA incluido)** de cada SKU dentro de una lista, fila a fila (el catálogo
  de precios puede ser de millones de filas: no vive dentro del agregado).
- Resolver, dado un SKU y un canal, un **presupuesto de precio** (`PriceQuote`) con la descomposición
  tributaria de Bolivia: **IVA 13 %** (por dentro, incluido en el precio) e **IT 3 %** (Impuesto a las
  Transacciones).

## No-responsabilidad

- No es dueño del catálogo/producto (eso es `commerce/catalog`); referencia el SKU canónico por valor.
- No cobra ni factura: la frontera con SAP (ADR 002) y la facturación viven fuera, vía outbox.
- No llama al Domain/Infrastructure de otros contextos: toda relación cruza por puertos/eventos.

## Modelo de impuestos (Bolivia)

- **IVA "por dentro"** (convención boliviana): el precio al público ya incluye el IVA. Dado un precio
  bruto, `iva = round(bruto * 13 %)` y `neto = bruto − iva`.
- **IT 3 %**: impuesto adicional sobre el bruto (`it = round(bruto * 3 %)`), expuesto en el desglose.
- Tasas configurables en **basis points** (IVA 1300 bp, IT 300 bp por defecto), redondeo exacto a
  centavos. El dinero SIEMPRE en centavos enteros, nunca float.

## Estado

Fase 0 del comercio: dominio + Application (CQRS) + persistencia Eloquent + tests. Sin Presentation
(API/Filament) todavía; el orquestador registra el package y el contrato de API en un cambio aparte.
