# logistics/capacity — Cupos en unidades de capacidad

## Responsabilidad

Administra los **cupos** de la red: cuánta capacidad hay disponible por nodo o zona, para una fecha y
una franja horaria, medida en una **unidad de capacidad** (entregas, kilogramos o metros cúbicos).

Cada cupo es un *bucket*: `(scope, fecha, franja, unidad)` con un total y un consumido. La planificación
**consume** capacidad al agendar un envío y la **libera** al reprogramar o cuando algo falla. El dominio
solo lleva la cuenta y aplica la regla dura de no exceder el total; decidir a qué bucket imputar es de
planning.

## No es responsable de

- Decidir rutas, camiones ni a qué nodo/zona asignar cada envío (eso es planning).
- Guardar las reglas de cupo de forma versionada a largo plazo: ese destino es `logistics/settings`.
  Mientras tanto el contrato `CapacityRuleProvider` lo satisface `ConfiguredCapacityRuleProvider`,
  que lee los totales por defecto de `config/logistics-capacity.php` (stub previsto; el día que las
  reglas pasen a settings se reemplaza solo el binding del provider).

## Modelo

- `CapacityBucket` (agregado): id, `BucketScope` (nodo **o** zona, uno de los dos), `SlotDate`,
  `TimeSlot`, `CapacityUnit`, `total` y `used` (`Quantity`).
  - `define()` crea el bucket y graba `CapacityDefined`.
  - `consume(Quantity)` imputa capacidad; lanza `CapacityException` si excede lo disponible.
  - `release(Quantity)` devuelve capacidad (nunca baja de 0).
  - `available(): Quantity`, `isFull(): bool`.
- `Quantity`: cantidad decimal (3 decimales) **no negativa**, con `add`/`subtract` (subtract no baja de 0).

## Uso (CQRS)

- Comando `DefineCapacity(node?|zone?, date, slot, unit, total)` → crea el bucket.
- Comando `ConsumeCapacity(node?|zone?, date, slot, unit, amount)` → consume del bucket.
- Comando `ReleaseCapacity(node?|zone?, date, slot, unit, amount)` → libera del bucket.
- Consulta `GetAvailableCapacity(node?|zone?, date, slot, unit)` → `CapacityView` o `null`.
- Consulta `GetEffectiveCapacity(node?|zone?, date, slot, unit)` → `EffectiveCapacityView` (nunca
  null): devuelve el cupo explícito si existe, el total por defecto de las reglas si no, o cero si no
  hay ni cupo ni regla (`source` = `bucket` | `rule` | `none`).
- Consulta `ListBucketsByDate(date)` → lista de `CapacityView` de esa fecha.

## API (tres puertas)

- `app` / `channels` (solo lectura): `GET capacity/available`, `GET capacity/effective`,
  `GET capacity/calendar`.
- `integrations` (escritura): `POST capacity/buckets` (define), `POST capacity/consume`,
  `POST capacity/release`.
- Contrato en `openapi/paths/capacity.yaml` (lo mergea el integrador en `openapi/v1.yaml`).

## Capas

- `Domain`: PHP puro (VOs, agregado `CapacityBucket`, eventos, `CapacityRepository`,
  `CapacityRuleProvider`, `CapacityException`).
- `Application`: comandos y consultas CQRS + `CapacityView` / `EffectiveCapacityView` como modelos de
  lectura.
- `Infrastructure`: `EloquentCapacityRepository`, `CapacityBucketRecord` + migración (`capacity_buckets`),
  `ConfiguredCapacityRuleProvider` (reglas desde `config/logistics-capacity.php`).
- `Presentation`: controllers HTTP por caso de uso + `CapacityBucketResource` de Filament (cupos y
  calendario, selects buscables de nodo/zona con datos reales).

## Datos de demostración

- `CapacityBucketFactory` (en `database/factories`) genera filas con estados (`forNode`, `forZone`,
  `onSlot`, `withUnit`, `withCapacity`).
- `CapacitySeeder` (en `database/seeders`) define cupos para los próximos días vía el `CommandBus`
  (idempotente por clave natural). El integrador lo invoca desde el `DatabaseSeeder` raíz.

## Estado

Fase 2. Las cuatro capas implementadas: dominio + CQRS, Infraestructura (record/repo/migración +
proveedor de reglas), Presentation (HTTP + Filament), factory, seeder, fragmentos de rutas y OpenAPI.
Integración de los archivos compartidos (`bootstrap/providers.php`, `composer.json` raíz, `routes/api.php`,
`openapi/v1.yaml`, `DatabaseSeeder`) y verificación en containers (pest / phpstan / pint) **pendientes**
(las hace quien integra).
