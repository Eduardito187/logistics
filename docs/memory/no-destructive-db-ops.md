---
name: no-destructive-db-ops
description: NUNCA borrar datos ni resetear la base; todo cambio es aditivo/idempotente; backup antes de algo crítico
metadata:
  node_type: memory
  type: feedback
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-13T19:59:46.645Z
---

Nunca correr operaciones destructivas sobre la base de desarrollo `logistics`: prohibido `migrate:fresh`, `migrate:refresh`, `db:wipe`, `TRUNCATE`, `DROP` para "verificar" o "empezar limpio". Borró dos veces el usuario admin del dueño (`ehhuallata@dismac.com.bo`) y lo dejó fuera del panel, además de arriesgar todo el progreso de datos sembrados/creados a mano.

**Why:** el dueño fue enfático (2026-10-13): los datos deben ser CRECIENTES, no "encontrar algo y borrar todo"; si algo es crítico, primero backup y luego proceder; ante la duda, consultar — nunca eliminar.

**How to apply:**
- Verificar seeders de forma NO destructiva: correr la suite `pest` (ya usa `logistics_test`, aislado) y/o consultar la base actual con SELECT/JOINs; nunca `migrate:fresh` sobre `logistics`.
- Si de verdad hace falta re-sembrar, primero `pg_dump` a backup, avisar al usuario y pedir confirmación; preferir seeders idempotentes (`updateOrCreate`/`firstOrCreate`) que se puedan re-correr sin borrar.
- Antes de cualquier migración/cambio de esquema riesgoso: backup con `docker compose exec -T postgres pg_dump -U logistics -d logistics --no-owner > backup.sql`.
- Al delegar a agentes, prohibirles explícitamente comandos destructivos de DB en sus instrucciones.

**Consecuencia concreta vista el 2026-10-13 (costó mucho enojo del dueño):** un `migrate:fresh` (o cualquier reset) durante la sesión **regenera todos los UUID v7 de los datos de demo**. Como el admin Filament rutea por UUID (`/admin/<recurso>/<uuid>`), eso deja **todos los deep-links del dueño en 404** (pestañas, marcadores y el "intended" al re-loguear apuntan a UUID viejos ya borrados). Es irreversible: esos IDs no vuelven. Señal de que pasó: todas las migraciones quedan en *batch 1* en `migrate:status`.

**Estado 2026-10-13:** los 16 seeders de contexto son idempotentes (guardia skip-if-exists o `findBy`/`updateOrCreate` por clave de negocio, sin regenerar UUID). `db:seed` corrido dos veces seguidas = todo DONE, EXIT 0, sin duplicar ni cambiar IDs. Red de seguridad extra: un deep-link del admin a un registro inexistente ya NO da 404, redirige al listado del recurso (ver [[filament-admin-hardening]]).

Relacionado: [[seed-data-cross-refs]] (el trabajo cuya verificación causó el borrado), [[filament-admin-hardening]], [[project-rules]].
