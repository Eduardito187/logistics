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

Dominio y casos de uso base implementados (DDD + CQRS sobre `logistics/shared`).

### Dominio

- IDs tipados: `VehicleId`, `DriverId`, `CrewId` (UUID v7).
- Value objects: `Capacity` (kg entero + m³), `PlateNumber`, `LicenseNumber`,
  `DocumentExpiry` (`isExpiredAt`, `expiresWithin`), `PreTripChecklist`.
- Enums: `VehicleStatus` (available / in_service / maintenance / inactive), `CrewRole` (driver / helper).
- Agregados: `Vehicle` (mantenimiento, documentos por vencer), `Driver` (licencia, habilitación por
  vehículo), `Crew` (tripulación chofer + ayudantes + vehículo).
- Eventos: `VehicleRegistered`, `VehicleStatusChanged`, `DriverRegistered`, `CrewAssembled`.
- Contratos de repositorio: `VehicleRepository`, `DriverRepository`, `CrewRepository`.

### Aplicación (CQRS)

- Commands: `RegisterVehicle`, `ChangeVehicleStatus`, `RegisterDriver`, `AssembleCrew`.
- Queries: `ListVehicles`, `ListDrivers`, `ListExpiringDocuments`.
- Read models: `VehicleView`, `DriverView`, `ExpiringDocumentView`.

### Infraestructura

- Modelos Eloquent, mappers y repositorios para las tablas `fleet_vehicles`, `fleet_drivers`,
  `fleet_crews` (PK UUID en texto, no autoincremental).
- `FleetServiceProvider` bindea los repositorios y registra los handlers en el `HandlerRegistry`.

### Pendiente

- Publicar los eventos liberados vía outbox al integrar el package correspondiente (hoy se liberan y
  se descartan; ver los `TODO(outbox)` en los handlers).
- Presentación (HTTP / Filament / consola) y checklist pre-salida persistido.
