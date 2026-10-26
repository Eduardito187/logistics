---
name: api-readmodel-enrichment
description: Patrón para que APIs/admin muestren el valor legible junto a cada FK UUID (resolución cross-context + bug de casts Carbon)
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-13T20:01:33.353Z
---

Cómo exponer el valor legible relacionado junto a un UUID (FK) en las respuestas de API y en el admin, sin acoplar contextos. Implementado el 2026-10-13 (carriers, tracking, notifications, promise).

**Dónde se arma el payload:** en los ReadModel `*View::toArray()` (capa Application), NO en los controllers (que solo envuelven en `{data: ...}`). El `from*()` del View copia el UUID crudo del agregado. Para enriquecer: el **QueryHandler** resuelve el valor legible y lo pasa al View como argumento opcional nuevo (`fromX(..., ?string $extra = null)`), y se agrega la clave en `toArray()`. Solo AÑADIR campos (retrocompatible).

**Resolución cross-context (sin dependencia composer nueva):** es un monorepo con autoload común, así que un QueryHandler puede leer el Record Eloquent de otro contexto (igual que ya hacen los `belongsTo` de `TrackingRecord`/`NotificationRecord` → `ShipmentRecord`). Resolver EN LOTE (`whereIn`) en listados para evitar N+1. Null si no resuelve. Si de verdad hiciera falta una dependencia composer entre contextos, NO forzarla: reportar. Mismo contexto (p.ej. `carrier_id`→`carrier_name` dentro de carriers) es trivial. `promise` está desacoplado a propósito: el nombre de zona se resuelve read-only desde `ZoneRecord` (coverage) en el handler/Resource, exponiéndolo como `zone_name`, sin relación Eloquent.

Enriquecimientos hechos: carriers `carrier_name` (Tariff/Settlement/Assignment), tracking/notifications `shipment_external_id` (desde `shipments.external_id`), promise `zone_name` (desde `coverage_zones.name`). `promise.shipment_ref` ya es un ref de negocio legible (`SHIP-…`), NO un UUID → no se enriquece. `PublicTrackingView` se deja intacto (sin PII, protegido por test).

**Contrato:** `openapi/v1.yaml` se actualiza en el MISMO cambio (regla del repo): añadir la propiedad nueva como nullable al schema (`Tariff`, `Settlement`, `ShipmentTracking`, `NotificationView`, `Promise`). Tests de contrato HTTP con `->withoutMiddleware()` (aislar el payload de las puertas auth) sembrando también la entidad relacionada. (Escribir los tests sí; ejecutarlos NO, ver [[no-ejecutar-tests]].)

**BUG recurrente de mapper (casts datetime):** si un Record castea una columna a `datetime` (`protected $casts = ['eta' => 'datetime']`), el atributo es **Carbon**, NO string. Un mapper que haga `new DateTimeImmutable($record->eta)` revienta con TypeError (500) cuando el campo trae valor. Fix: `$record->eta->toDateTimeImmutable()` (preserva zona horaria) y corregir el phpdoc `@property` a `\Illuminate\Support\Carbon|null`. Visto en `TrackingMapper` (`eta`, `last_position_at`, columnas `timestamptz`).

Relacionado: [[seed-data-cross-refs]] (las FK deben referenciar filas reales para que el join resuelva), [[filament-v4-component-gotchas]], [[project-architecture]].
