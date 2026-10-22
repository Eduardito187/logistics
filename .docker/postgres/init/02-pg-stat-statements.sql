-- ============================================================
-- Init de PostgreSQL — pg_stat_statements (M-6/C-3 auditoría)
-- ============================================================
-- Solo corre con el volumen de datos VACÍO (primer arranque). La librería
-- `pg_stat_statements` se precarga vía shared_preload_libraries en el `command`
-- del servicio postgres (docker-compose.yml); aquí se crea la EXTENSIÓN en cada
-- base para exponer la vista de estadísticas de queries (p95/p99, llamadas, I/O).
--
-- Si el volumen YA existe (este init no vuelve a correr), crear la extensión a mano:
--   docker compose exec postgres psql -U logistics -d logistics \
--     -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"
-- (idempotente, no destructivo).

CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

\connect logistics_test
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
