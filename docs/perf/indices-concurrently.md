# Convención: índices con `CREATE INDEX CONCURRENTLY` en tablas grandes

> Origen: C-4 de `docs/perf/AUDITORIA-devops-performance-2026-10-20.md`.

## El problema

`CREATE INDEX` normal toma un lock `ACCESS EXCLUSIVE` sobre la tabla mientras construye el índice.
Ese lock **bloquea toda escritura** (INSERT/UPDATE/DELETE) a la tabla durante la construcción. Sobre
tablas de alto volumen y crecimiento (`telemetry_pings`, `tracking_*`, `outbox_messages`, `shipments`,
`returns`, `execution_scan_events`, ...) eso es **downtime de escritura en pleno deploy**, justo cuando
la migración corre.

## La convención

Para crear o borrar índices sobre **tablas grandes o de crecimiento ilimitado**, usar
`CREATE INDEX CONCURRENTLY` / `DROP INDEX CONCURRENTLY`. CONCURRENTLY construye el índice sin bloquear
las escrituras (hace dos pasadas sobre la tabla), a cambio de dos condiciones:

1. **No puede correr dentro de una transacción.** El migrador de Laravel envuelve cada migración en una
   transacción cuando el driver lo soporta (PostgreSQL sí). Hay que desactivarlo por migración:

   ```php
   return new class extends Migration
   {
       // CONCURRENTLY no corre en transacción: el migrador no debe envolver esta migración.
       public $withinTransaction = false;

       public function up(): void
       {
           if (DB::getDriverName() !== 'pgsql') {
               return; // CONCURRENTLY es específico de PostgreSQL.
           }

           DB::statement(
               'CREATE INDEX CONCURRENTLY IF NOT EXISTS <nombre>_index ON <tabla> (<cols>)',
           );
       }

       public function down(): void
       {
           if (DB::getDriverName() !== 'pgsql') {
               return;
           }

           DB::statement('DROP INDEX CONCURRENTLY IF EXISTS <nombre>_index');
       }
   };
   ```

2. **Idempotencia obligatoria.** Usar `IF NOT EXISTS` / `IF EXISTS`. Si una creación CONCURRENTLY falla
   a mitad (p. ej. deadlock), Postgres deja un índice **INVALID** que hay que borrar a mano antes de
   reintentar; `IF NOT EXISTS` evita el error "ya existe" al re-correr `migrate`.

## Reglas

- **Una sentencia de índice por migración** (o pocas, independientes). CONCURRENTLY corre fuera de
  transacción: si la migración hace varias cosas y una falla, no hay rollback.
- **Guard `DB::getDriverName() === 'pgsql'`**: CONCURRENTLY y los índices parciales son de PostgreSQL;
  en otros drivers (p. ej. tests que no sean pgsql) se omite.
- **Documentar la consulta que respalda cada índice** en el docblock de la migración (qué `WHERE` /
  `ORDER BY` lo usa), para que un índice no quede huérfano cuando cambie el repositorio.
- **No hace falta para tablas chicas / catálogos** (nodos, zonas, roles): ahí un índice normal es más
  simple y el lock dura milisegundos. CONCURRENTLY es para tablas que crecen sin tope.

## Tests (RefreshDatabase)

Las migraciones corren en `migrate:fresh`, **antes** de que arranque la transacción por-test de
`RefreshDatabase`. Con `$withinTransaction = false` la sentencia CONCURRENTLY no queda dentro de ninguna
transacción y se ejecuta igual que en producción; **no hace falta degradar a índice normal en test**. Si
en algún entorno el driver de test no fuera PostgreSQL, el guard `pgsql` omite la sentencia.

## Ejemplo en el repo

`packages/logistics/returns/database/migrations/2026_10_20_000100_add_status_created_index_to_returns_table.php`
(índice `returns (status, created_at)`).
