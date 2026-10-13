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
- Guardar las reglas de cupo configurables: esas viven como configuración versionada en
  `logistics/settings`. Acá queda solo el **contrato** `CapacityRuleProvider` que luego las leerá.

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
- Consulta `ListBucketsByDate(date)` → lista de `CapacityView` de esa fecha.

## Capas

- `Domain`: PHP puro (VOs, agregado `CapacityBucket`, eventos, `CapacityRepository`, `CapacityException`).
- `Application`: comandos y consultas CQRS + `CapacityView` como modelo de lectura.
- `Infrastructure`: `EloquentCapacityRepository`, modelo y migración (`capacity_buckets`).
- `Presentation`: sin entregables aún.

## Estado

Fase 2. Dominio y CQRS implementados; `php -l` limpio en todo el package. Integración en
`bootstrap/providers.php` y `composer.json` raíz, y verificación en containers (pest / phpstan / pint),
**pendientes** (las hace quien integra). El contrato `CapacityRuleProvider` queda sin implementación:
lo cableará `logistics/settings` más adelante.
