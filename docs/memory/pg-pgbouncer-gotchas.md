---
name: pg-pgbouncer-gotchas
description: "Gotchas de Postgres+PgBouncer y de identidad de usuario admin — \"cached plan\" tras DDL en vivo, y columnas uuid vs id entero del admin"
metadata:
  node_type: memory
  type: reference
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-05T05:43:04.606Z
---

Dos errores que aparecieron al tocar el esquema y cómo se resuelven:

1) **`invalid input syntax for type uuid: "2"`** al escribir una columna `*_by`/actor. El admin de Filament
   (`App\Models\User`, tabla `users`) usa **id ENTERO (bigint)** — p. ej. `2` —, NO un UUID. Varias tablas de
   dominio guardan "quién hizo la acción" como string flexible (`varchar(64)`), pero alguna quedó como `uuid`
   por deriva de migración → rechaza el id entero. Regla: las columnas que reciben el id del admin
   (`created_by`, `acknowledged_by`, `actor_id`, `blocked_by`…) deben ser `varchar`, NO `uuid`.
   **Auditado 2026-11-05: TODAS las `*_by` y `actor_id` ya son `character varying`** (se corrigió
   `dispatch_blackout_days.created_by` uuid→varchar(64) con la migración `2026_11_05_000100_fix_dispatch_blackout_created_by_type`).
   Si agregás una columna de actor nueva, tipala `string(64)` (ver [[access-iam-context]]).

2) **`cached plan must not change result type`** (SQLSTATE 0A000) con PgBouncer (transaction pooling, puerto
   6432). Pasa cuando se corre un **ALTER TABLE ... TYPE mientras la app está SIRVIENDO**: los backends de
   Postgres que PgBouncer mantiene vivos tienen planes cacheados (prepared statements, `SELECT *`) con el tipo
   viejo. Fix inmediato: **`docker compose restart pgbouncer app`** (vacía los backends → replanifican). NO
   recurre en el flujo normal: `AUTO_MIGRATE` corre en el entrypoint ANTES de que php-fpm sirva, así las
   conexiones que atienden requests se crean después del migrate. Solo pasa con migraciones MANUALES en vivo
   (`make migrate` / `php artisan migrate` con la app ya levantada) → reiniciar pgbouncer después. Ver [[project-architecture]] (PgBouncer C-3).
