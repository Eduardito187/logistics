# Memoria: Patrón Octane-safe — static memoization → Cache::remember (2026-11-13/14)

## El problema

Bajo PHP-FPM, `private static ?array $cache = null` es "por request" porque el proceso muere
tras cada request. Bajo **Octane** (FrankenPHP/Swoole/RoadRunner), el worker **vive entre
requests**: esa estática serviría datos viejos indefinidamente y crecería en memoria.

## El patrón aplicado (Ola 2 auditoría)

Reemplazar `static $cache` por `Cache::remember('clave', TTL, fn() => ...)`:

```php
// ANTES (FPM-only, peligroso bajo Octane):
private static ?array $map = null;
public static function options(): array {
    if (self::$map === null) {
        self::$map = Model::pluck('name', 'id')->all();
    }
    return self::$map;
}

// DESPUÉS (Octane-safe + más rápido en FPM también):
private const CACHE_TTL_SECONDS = 60;
public static function options(): array {
    return Cache::remember('admin:model-options', self::CACHE_TTL_SECONDS, static function (): array {
        return Model::pluck('name', 'id')->all();
    });
}
```

## Claves namespaced y compartidas

- Prefijo `admin:` para datos de presentación del panel Filament
- Misma clave cuando múltiples clases resuelven la misma tabla:
  - `admin:vehicle-plates` → `VehiclePlates` (fleetops), `VehiclePlates` (telemetry), `ResolvesVehiclePlate`
  - `admin:seller-names` → `MaterialMappingResource`, `StockTransferResource`, `StockOnHandResource`, `SellerSettlementResource`
  - `admin:node-names:*` → variantes por contexto (coverage, execution, telemetry)

## Clases migradas (17 en total)

| Clase | Clave de caché |
|-------|---------------|
| `VehiclePlates` (fleetops) | `admin:vehicle-plates` |
| `VehiclePlates` (telemetry) | `admin:vehicle-plates` |
| `ResolvesVehiclePlate` | `admin:vehicle-plates` |
| `NodeNames` (coverage) | `admin:node-names:coverage` |
| `NodeNames` (execution) | `admin:node-names:execution` |
| `NodeNames` (telemetry) | `admin:node-names:telemetry` |
| `TripLookups` | `admin:trip-node-names` |
| `UserNames` | `admin:user-names` |
| `UnitNames` | `admin:unit-name:{id}` |
| `MunicipalityOptions` | `admin:municipality-options` |
| `CategoryResource` | `admin:catalog:category-options` |
| `ProductResource` | `admin:catalog:category-options` |
| `PromiseResource` | `admin:promise-zone-names` |
| `DocumentResource` | `admin:fleet-expiring-documents` |
| `StockOnHandResource` | `admin:stock-warehouse-names`, `admin:seller-names` |
| `MaterialMappingResource` | `admin:seller-names` |
| `StockTransferResource` | `admin:seller-names` |
| `SellerSettlementResource` | `admin:seller-names` |

## Regla 7 de CLAUDE.md

Se cachean formas primitivas (`array<string, string>`, `array{count: int, expired: bool}`),
**nunca** ReadModels ni objetos de dominio serializados. `DocumentResource` cachea
`{count, expired}` (primitivo), no la lista de `ExpiringDocumentView`.

## En tests

Donde antes se hacía `ReflectionProperty::setValue(null)` para invalidar la estática,
ahora se hace `Cache::forget('admin:vehicle-plates')` (ver `CoverageFleetCostDashboardTest`).
