# Expansión del módulo Flota/Personal — contrato (aditivo)

Objetivo: enriquecer choferes y ayudantes con datos personales (foto, CI, edad, teléfonos, referencias,
categoría de licencia, documentos/escaneos) y habilitar el **historial operativo cuantificable** (qué
chofer manejó qué camión qué día, con qué ayudantes, cuántos entregó, cuántos retornaron y por qué).

Reglas: DDD+CQRS+Clean (skill `bounded-context`), **todo ADITIVO** (nuevas columnas nullable / tablas /
VOs; nunca borrar ni `migrate:fresh`). UI Filament en inglés + `lang/es.json`. Comentarios en español.
**NO ejecutar tests.** `migrate` **hacia adelante** sí (es aditivo); jamás fresh/rollback/wipe.

## 1. Esquema (migraciones aditivas nuevas)

`fleet_drivers` — añadir (todas nullable):
`photo_path varchar(1024)`, `birth_date date`, `national_id varchar(40)`, `phone_primary varchar(40)`,
`phone_secondary varchar(40)`, `address text`, `license_category varchar(20)`,
`emergency_contact_name varchar(160)`, `emergency_contact_phone varchar(40)`, `references jsonb default '[]'`.

`fleet_helpers` — añadir (nullable): `photo_path varchar(1024)`, `birth_date date`,
`national_id varchar(40)`, `phone_secondary varchar(40)`, `address text`,
`emergency_contact_name varchar(160)`, `emergency_contact_phone varchar(40)`, `references jsonb default '[]'`.
(El `phone` existente es el teléfono primario del ayudante.)

`execution_driver_routes` — añadir: `driver_id uuid` (nullable, index), `helper_ids jsonb` (nullable).
Se pueblan al crear la ruta a partir del crew del vehículo+fecha (best-effort); si no, el historial cae
al join por (vehicle_id, fecha) con `fleet_crews`.

`references` jsonb = lista de `[{name, relationship, phone, note}]` (misma idea que `documents`).

## 2. Value objects / enums nuevos (fleet Domain/ValueObject)

- `BirthDate`: `of(DateTimeImmutable)`, `ageAt(DateTimeImmutable): int`, `toString(): 'Y-m-d'`.
- `NationalId`: `of(string)` (trim, no vacío), `->value`.
- `PhoneNumber`: `of(string)` (normaliza; acepta dígitos, `+ - space`, 6–20), `->value`.
- `PersonalReference` (final readonly): `name`, `?relationship`, `?phone`, `?note`; `of(...)`, `toArray()`.
- `LicenseCategory` (backed enum, Bolivia): `P`,`A`,`B`,`C`,`M` (+ `label()`); uso nullable.
- `FleetDocumentType` (backed enum): `national_id`,`driver_license`,`health_card`,`criminal_record`,`photo`,`other` (+ `label()`). La columna `document_type` sigue string; el enum clasifica los conocidos y `other` permite libre.

## 3. Agregados / comandos (fleet Application)

- `Driver`: añadir props personales OPCIONALES (nullable) + `array $references` (list<PersonalReference>).
  `register()` mantiene su firma actual y suma params opcionales con default null (retrocompatible).
  Nuevo método `updateProfile(DriverProfile)` (emite `DriverProfileUpdated`). VO `DriverProfile` (readonly)
  agrupa los campos personales + references. Comando **`UpdateDriverProfile`**(driverId, photoPath?, birthDate?,
  nationalId?, phonePrimary?, phoneSecondary?, address?, licenseCategory?, emergencyContactName?,
  emergencyContactPhone?, references[]) + handler.
- `Helper`: análogo. VO `HelperProfile`, método `updateProfile`, evento `HelperProfileUpdated`, comando
  **`UpdateHelperProfile`**(helperId, photoPath?, birthDate?, nationalId?, phoneSecondary?, address?,
  emergencyContactName?, emergencyContactPhone?, references[]).
- Mappers/records/repos: mapear las columnas nuevas; `references`/`documents` jsonb ↔ list<VO>. Los Model
  Eloquent castean `references` a array.
- Las queries existentes (DriverView/HelperView) se extienden con los campos nuevos (aditivo en `toArray`).
- Documentos/fotos: se siguen adjuntando por el agregado `FleetDocument` existente (owner_type driver/helper
  + `file_path` en disco S3 `documents`), ahora con `document_type` del enum `FleetDocumentType`. La FOTO de
  perfil se guarda en `fleet_drivers.photo_path`/`fleet_helpers.photo_path` (key en el disco `documents`).

## 4. Historial operativo (read model + proyección)

Read model `DriverHistoryView` (fleet Application/ReadModel) para `GetDriverHistory(driverId, from, to)`:
- Totales: `days_worked`, `vehicles` (placas distintas), `total_stops`, `delivered`, `failed`,
  `failed_by_reason` {FailureReason→count}, `rescheduled`.
- Desglose por día: `[{date, vehicle_plate, helper_names[], delivered, failed, rescheduled}]`.
Fuente (read side, lee *Record Eloquent cross-context): `fleet_crews` (driver_id, assembled_for, vehicle_id,
helper_ids) ⋈ `execution_driver_routes` (por `driver_id` si está, si no por vehicle_id+route_date) ⋈
`execution_driver_route_stops` (status COMPLETED/FAILED/RESCHEDULED) + `execution_deliveries`
(failure_reason) + `execution_vehicle_incidents` (motivo de reprogramación). Ayudantes: nombres desde
`fleet_helpers` por helper_ids del crew.

## 5. Filament (grupo Fleet, UI inglés + lang/es.json)

- `DriverResource`/`HelperResource`: añadir los campos personales al form (DatePicker birth_date mostrando
  edad; national_id; phones; address; license_category Select `->native(false)`; emergency contact; Repeater
  `references`), **FileUpload de foto** (`->disk(config('fleet.documents_disk'))`, private, image), y habilitar
  **edición** despachando `UpdateDriverProfile`/`UpdateHelperProfile`. Mostrar edad y foto (ImageColumn/Entry
  cuadrada pequeña — ver [[filament-v4-component-gotchas]], nunca `->circular()` gigante). RelationManager o
  sección de **documentos** del chofer/ayudante (reusar fleet_documents filtrando owner).
- Historial: **página/Resource Filament NUEVO** (no tocar DriverResource para evitar conflicto): "Driver
  history/performance" que consume `GetDriverHistory` y muestra totales + desglose por día (badges por
  motivo de fallo). Select de chofer `->native(false)` buscable con datos reales.

## 6. Verificación (sin tests)

PHPStan nivel 8 sobre lo tocado, Pint, `php -l`, `php artisan migrate` (SOLO hacia adelante, aditivo) +
`route:list`. Reportar números. El build de Vite (si hay SPA) lo hace el orquestador.
