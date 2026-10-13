# logistics/coverage — Cobertura

## Responsabilidad

Jerarquía Departamento > Provincia > Municipio > Ciudad > Zona (polígono PostGIS) > Microzona. Resolución punto→zona. Reglas por zona: nodo que atiende, servicios, tiempos base, límites por producto, restricciones horarias, días de servicio. Importa el mapa de cobertura actual (KML/GeoJSON).

## No es responsable de

Cupos y capacidad (Fase 2).

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Dominio implementado (Fase 1, sin cupos):

- **Domain**: `Zone` (agregado) y `GeoArea` (entidad); VOs `GeoPoint`, `Polygon`, `AdminLevel`,
  `ServiceDays`, `TimeWindow`, `ZoneRules`; IDs `ZoneId`/`GeoAreaId`; eventos `ZoneDefined` y
  `ZoneRulesUpdated`; contratos `ZoneRepository`/`GeoAreaRepository`; servicio
  `GeoJsonCoverageParser`; excepción `CoverageException`.
- **Application (CQRS)**: comandos `DefineZone`, `UpdateZoneRules`, `ImportCoverageMap`; consultas
  `ResolveZoneForPoint`, `ListZones`; read model `ZoneView`.
- **Infrastructure**: `EloquentZoneRepository` (PostGIS: polígono en `geometry(Polygon,4326)`,
  punto→zona con `ST_Contains`) y `EloquentGeoAreaRepository`, con `ZoneMapper`. Migraciones de
  `coverage_geo_areas` y `coverage_zones` (+ columna `area` e índice GIST por SQL). Factories
  (`GeoAreaRecordFactory`, `ZoneRecordFactory` — esta rellena la geometría por SQL en `afterCreating`)
  y `CoverageSeeder` con datos de demo de Bolivia (La Paz, Santa Cruz, Cochabamba). Los handlers de
  escritura publican sus eventos al outbox (`RecordDomainEvents`) en la misma transacción.
- **Presentation**: comando `logistics:coverage:resolve {lat} {lng}`; controllers HTTP para las
  puertas `app`/`integrations` (resolver, listar, importar GeoJSON y KML) en el fragmento
  `routes/api/coverage.php`; Filament `ZoneResource` y `GeoAreaResource`.

## Importadores del mapa de cobertura

Dos formatos, mismo destino (`ParsedZone` → `Zone`), ambos idempotentes a nivel de caso de uso:

- **GeoJSON**: `GeoJsonCoverageParser` + comando `ImportCoverageMap` (`POST /integrations/coverage/map:import`).
- **KML**: `KmlCoverageParser` (DOMDocument, PHP puro) + comando `ImportCoverageKml`
  (`POST /integrations/coverage/map:import-kml`, cuerpo XML crudo). Lee `<Placemark>` (name, Polygon,
  ExtendedData con node/services/baseMinutes/serviceDays) y cierra el anillo si viene abierto.

La resolución punto→zona se prueba en un test Feature (necesita PostGIS); los VOs, el agregado y el
parser en tests Unit.
