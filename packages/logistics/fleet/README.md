# logistics/fleet — Flota y tripulación

## Responsabilidad

Vehículos (capacidad kg/m³, CD base, estado, documentos con vencimiento), choferes (licencia, vencimiento, vehículos habilitados), ayudantes, turnos y descansos. Asignación de tripulación y checklist pre-salida.

## No es responsable de

Planificar qué pedidos lleva cada camión.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Contexto completo de punta a punta (DDD + CQRS + Clean sobre `logistics/shared`, con 6 modelos).

### Dominio

- IDs tipados (UUID v7): `VehicleId`, `DriverId`, `HelperId`, `CrewId`, `PreTripInspectionId`,
  `FleetDocumentId`.
- Value objects: `Capacity` (kg entero + m³), `PlateNumber`, `LicenseNumber`,
  `DocumentExpiry` (`isExpiredAt`, `expiresWithin`), `PreTripChecklist`.
- Enums: `VehicleStatus` (available / in_service / maintenance / inactive), `CrewRole` (driver / helper),
  `DocumentOwnerType` (vehicle / driver / helper).
- Agregados: `Vehicle` (mantenimiento, documentos por vencer), `Driver` (licencia, habilitación por
  vehículo), `Helper` (ayudante con documentos y disponibilidad), `Crew` (tripulación chofer +
  ayudantes + vehículo), `PreTripInspection` (checklist pre-salida ejecutado, apta/no apta),
  `FleetDocument` (documento con vencimiento y archivo en S3).
- Eventos: `VehicleRegistered`, `VehicleStatusChanged`, `DriverRegistered`, `HelperRegistered`,
  `HelperAvailabilityChanged`, `CrewAssembled`, `PreTripChecklistConducted`, `DocumentRegistered`.
- Contratos de repositorio: `VehicleRepository`, `DriverRepository`, `HelperRepository`,
  `CrewRepository`, `PreTripInspectionRepository`, `DocumentRepository`.

### Aplicación (CQRS)

- Commands: `RegisterVehicle`, `ChangeVehicleStatus`, `RegisterDriver`, `RegisterHelper`,
  `SetHelperAvailability`, `AssembleCrew`, `ConductPreTripChecklist`, `RegisterDocument`.
- Queries: `ListVehicles`, `ListDrivers`, `ListHelpers`, `ListPreTripChecklists`, `ListDocuments`,
  `ListExpiringDocuments` (alertas: reúne vehículos, choferes, ayudantes y documentos de flota).
- Read models: `VehicleView`, `DriverView`, `HelperView`, `PreTripChecklistView`, `DocumentView`,
  `ExpiringDocumentView`.

### Infraestructura

- Modelos Eloquent, mappers y repositorios para las tablas `fleet_vehicles`, `fleet_drivers`,
  `fleet_helpers`, `fleet_crews`, `fleet_pre_trip_checklists`, `fleet_documents` (PK UUID en texto, no
  autoincremental; `jsonb` para documentos/ítems).
- `FleetServiceProvider` fusiona `config/fleet.php` (disco S3 de documentos, ventana de alertas),
  bindea los repositorios y registra los handlers en el `HandlerRegistry`.
- Eventos liberados por cada agregado y publicados al outbox (`RecordDomainEvents`) desde los handlers
  de escritura.

### Presentación

- HTTP (buses CQRS): `VehicleController`, `DriverController`, `HelperController`, `CrewController`,
  `PreTripChecklistController`, `DocumentController`, `ExpiringDocumentController`. Rutas en el
  fragmento `routes/api/fleet.php` (puertas `app` e `integrations`); contrato en `openapi/paths/fleet.yaml`.
- Filament (texto en inglés, selects `->native(false)` con datos reales): `VehicleResource`,
  `DriverResource`, `HelperResource`, `CrewResource`, `PreTripChecklistResource`, `DocumentResource`.

### Datos de demo

- Factories por cada modelo en `database/factories/` y `FleetSeeder` (`database/seeders/`) con flota,
  tripulaciones, inspecciones y documentos coherentes (incluye vencidos para probar las alertas).

### Pendiente / integradores

- Autoload de `Logistics\Fleet\Database\Factories\` y `...\Seeders\` en el `composer.json` raíz
  (autoload-dev), e invocar `FleetSeeder` desde `DatabaseSeeder`: lo hace el integrador (archivos
  compartidos).
- Verificación en containers (pest / phpstan 8 / pint): pendiente por la regla #1 de `CLAUDE.md`.
