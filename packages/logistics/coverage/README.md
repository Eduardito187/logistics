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
  `ServiceDays`, `TimeWindow`, `ZoneRules`, `CoverageFormat`; IDs `ZoneId`/`GeoAreaId`; eventos
  `ZoneDefined` y `ZoneRulesUpdated`; contratos `ZoneRepository`/`GeoAreaRepository` (con búsqueda por
  clave estable: `findByNameAndParent`/`findByNameAndLevel`); servicios `GeoJsonCoverageParser` y
  `KmlCoverageParser`; excepción `CoverageException`.
- **Application (CQRS)**: comandos `DefineZone`, `UpdateZoneRules`, `ImportCoverageMap`,
  `ImportCoverageKml`, `ImportCoverage` (importador idempotente por departamento); consultas
  `ResolveZoneForPoint`, `ResolveDepartmentForPoint`, `ListZones`; read model `ZoneView`; colector
  `ImportCoverageReport` (altas/actualizaciones/errores de una importación).
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

Dos formatos (`ParsedZone` → `Zone`), con parsers de dominio reutilizables:

- **GeoJSON**: `GeoJsonCoverageParser`.
- **KML**: `KmlCoverageParser` (DOMDocument, PHP puro). Lee `<Placemark>` (name, Polygon, ExtendedData
  con node/services/baseMinutes/serviceDays) y cierra el anillo si viene abierto.

Dos caminos de ingesta:

1. **Append-only (legacy)**: `ImportCoverageMap` (`POST /integrations/coverage/map:import`) e
   `ImportCoverageKml` (`POST /integrations/coverage/map:import-kml`). Crean una zona por feature en
   cada ejecución (el documento no trae id); sirven para empujar el mapa completo desde un proceso
   externo. NO son idempotentes: reimportar duplica.
2. **Idempotente por departamento**: comando `ImportCoverage(departmentName, content, CoverageFormat)`.
   - Da de alta el departamento (`GeoArea` nivel Department) por su clave estable (nombre) o lo reusa.
   - Da de alta/actualiza cada zona por su clave estable (nombre + área padre): si ya existe, se
     reconstruye con su MISMO `ZoneId` y se persiste encima (upsert `ON CONFLICT (id)`), de modo que
     geometría y reglas se actualizan sin cambiar el id (deep-links del admin intactos). Reimportar el
     mismo mapa NO duplica zonas ni el departamento.
   - El resultado (zonas creadas/actualizadas/errores) se recoge en `ImportCoverageReport` (el handler
     es void por CQRS; el colector es un singleton de alcance de request que lee la Presentation).
   - **Admin**: acción de cabecera **Import coverage** del `ZoneResource` (subir archivo `.kml`/
     `.geojson`/`.json` + elegir departamento); muestra una notificación con el reporte.

La resolución punto→zona y la importación idempotente real (upsert PostGIS, clave estable con
`IS NOT DISTINCT FROM`) se prueban en tests Feature (necesitan PostGIS); los VOs, el agregado, los
parsers, el `ImportCoverageReport` y la idempotencia del handler (con repos en memoria) en tests Unit.
