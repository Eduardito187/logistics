---
name: seed-data-cross-refs
description: Por qué los campos de relación salen vacíos en el admin — seeders con FKs cruzadas huérfanas
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-13T15:48:30.574Z
---

Los seeders de cada bounded context generaban sus propios UUID v7 al azar, así que las foreign keys cross-context quedaban huérfanas: apuntaban a filas inexistentes en la tabla referenciada. Efecto visible: en el admin Filament, las columnas/infolists de relación (`shipment.external_id`, `vehicle.plate`, etc.) salen vacías aunque la relación Eloquent esté bien definida, porque la fila relacionada no existe.

Confirmado el 2026-10-13: de 3 filas en `execution_deliveries`, 0 referencian un shipment o vehículo existente (JOIN = 0), pese a haber 6 shipments y 8 fleet_vehicles.

**Why:** el usuario reportó "las relaciones no muestran nada, solo el campo vacío"; el 90% del problema era datos huérfanos, no código de presentación.

**How to apply:** los seeders downstream deben LEER IDs reales de las tablas upstream ya sembradas (el orden de dependencia ya está en `database/seeders/DatabaseSeeder.php`), no generar UUID random. Verificar siempre con `migrate:fresh --seed` + JOINs que las FKs resuelvan. Relacionado: [[project-rules]].
