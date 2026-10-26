---
name: fleet-personnel-expansion
description: "Expansión de flota/personal — datos personales de choferes/ayudantes, documentos, referencias, historial operativo (GetDriverHistory)"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-15T00:49:55.392Z
---

Expansión 2026-10-13 del contexto `packages/logistics/fleet` (aditiva, DDD/CQRS). Ver contrato
`docs/fleet-expansion.md`.

**Datos personales (Driver y Helper)** — columnas aditivas nullable en `fleet_drivers`/`fleet_helpers`:
photo_path, birth_date (→ edad), national_id, phone_primary/secondary, address, license_category (solo
driver), emergency_contact_name/phone, y `references` jsonb (list de {name, relationship, phone, note}).
VOs nuevos: BirthDate (ageAt), NationalId, PhoneNumber, PersonalReference, DriverProfile/HelperProfile,
enums LicenseCategory (Bolivia P/A/B/C/M) y FleetDocumentType. Comandos nuevos `UpdateDriverProfile` /
`UpdateHelperProfile` (register() quedó retrocompatible con params opcionales). ReadModels DriverView/
HelperView extendidos (incl. `age`). Driver NO tiene comando de disponibilidad (su `active` no se edita);
Helper sí (SetHelperAvailability).

**Documentos/fotos**: se reusa el agregado `FleetDocument` (tabla polimórfica `fleet_documents`, owner_type
driver/helper/vehicle, `file_path` = key en disco S3 privado `documents`, firmado al descargar). La foto de
perfil va en `*.photo_path`. En Filament: FileUpload a `config('fleet.documents_disk')`; `document_type` es
Select del enum FleetDocumentType (con opción 'other' libre). El Model polimórfico no se tocó: la lista de
documentos por persona se arma consultando `FleetDocumentModel` por owner_type+owner_id (helper `OwnerDocuments`).

**Filament**: DriverResource/HelperResource ganaron edición (despacha UpdateX Profile), foto (ImageColumn/
Entry CUADRADA, ver [[filament-v4-component-gotchas]]), edad, referencias (Repeater), y acción "Register
document". Página nueva "Driver performance" (`admin/driver-history`) consume GetDriverHistory.

**Historial operativo — `GetDriverHistory(driverId, from, to)`** (fleet Application; read-side cross-context):
agrega por chofer en un rango: days_worked, vehicles[], total_stops, delivered, failed, failed_by_reason,
rescheduled + desglose por día. El enlace chofer↔ruta↔entregas se resuelve así (NO hay entidad "jornada"):
`fleet_crews` (driver_id, assembled_for, vehicle_id, helper_ids) ⋈ `execution_driver_routes` — por
`execution_driver_routes.driver_id` si está poblado (columna aditiva nueva, + helper_ids jsonb), o por
(vehicle_id, route_date == assembled_for) — ⋈ `execution_driver_route_stops` (COMPLETED/FAILED/RESCHEDULED)
+ `execution_deliveries` (failure_reason) + `execution_vehicle_incidents`.
- **GOTCHA corregido**: indexar las rutas por (vehicle_id, fecha), NO por fecha sola, y **deduplicar cada
  ruta**; si no, un chofer con dos tripulaciones el mismo día cuenta la misma ruta/paradas dos veces
  (total_stops salía x2). Las rutas de OTRO chofer (driver_id distinto) se descartan.
- El agregado de Execution que crea rutas (`AssembleDailyRouteHandler`/`VehicleRoute`) NO conoce tripulación,
  así que `execution_driver_routes.driver_id` no se puebla en el flujo normal; se dejó la columna y el
  historial cae al join por vehículo+fecha. En DEMO, `ExecutionDemoSeeder::alignDriverRoutesWithCrews()`
  (idempotente) crea un crew por (vehículo, fecha de ruta) y puebla driver_id/helper_ids para que el panel
  muestre datos. Relacionado: [[seed-data-cross-refs]].

Verificado: PHPStan 8 limpio en fleet/execution/planning/app, Pint, migrate --force (solo hacia adelante,
[[no-destructive-db-ops]]), y GetDriverHistory en runtime. (La vieja regla de no-ejecutar-tests fue
DEROGADA por el dueño; hoy la DoD exige correr Pest — ver [[roadmap-build-plan]].)

## Expansión de VEHÍCULOS + alertas de vencimiento (2026-10-14, aditiva)
**Vehículo** (`fleet_vehicles`, antes solo plate/capacity/node/status/documents): columnas aditivas nullable
make, model, model_year(smallint), vin (nº de serie/chasis), engine_number, color, y photo_front/back/left/
right (fotos de los 4 lados). VO `VehicleProfile`, evento `VehicleProfileUpdated`, comando
`UpdateVehicleProfile` (patrón de UpdateDriverProfile). `VehicleResource` muestra la ficha + las 4 fotos
(ImageFallback) + documentos del vehículo.
**Documentos de vehículo**: se reusa `fleet_documents` con `DocumentOwnerType::Vehicle` (ya existía) +
`OwnerDocuments` extendido a vehicle|driver|helper. Nuevos `FleetDocumentType`: TechnicalInspection
(inspección técnica, anual) y Soat. Columna aditiva `fleet_documents.reference` para el nº de póliza/serie
(SOAT) o nº de certificado (inspección). Cada documento muestra badge Vigente/Por vencer/Vencido.
**Sistema de ALERTAS de vencimiento** (licencias + documentos): escáner que clasifica por umbral
VENCIDO / ≤7 días / ≤30 días (se EXTENDIÓ el mecanismo existente `AlertExpiringDocumentsHandler` +
`FleetDocumentsExpiryAlerted`, idempotente por día vía eventId determinista → el outbox no re-alerta el
mismo día). Scheduler ya tenía la entrada diaria (`logistics:fleet:document-alerts` 07:00). Widget
`LicensesDocumentsExpiringWidget` (app/Filament/Widgets) agrupa por umbral con enlace al recurso. También
se agregó `license_city` al chofer. Ver [[filament-v4-component-gotchas]] (fallback de imágenes).
