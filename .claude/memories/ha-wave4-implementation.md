# Memoria: Ola 4 — Alta disponibilidad implementada (2026-11-14)

## Qué se hizo

Se implementó la Ola 4 completa del roadmap de alta disponibilidad (`docs/roadmap-alta-disponibilidad-escala.md`).

### Archivos creados

| Archivo | Propósito |
|---------|-----------|
| `docker-compose.ha.yml` | Override de compose para topología HA (multi-app, PG standby, Valkey Sentinel) |
| `app/Console/Commands/QueueHealthCheckCommand.php` | Monitoreo de profundidad de colas (`logistics:queue:health`) |
| `app/Console/Commands/RestoreDrillCommand.php` | Simulacro de restauración no destructivo (`logistics:restore-drill`) |
| `app/Console/Commands/PreDeployCommand.php` | Validación pre-deploy zero-downtime (`logistics:pre-deploy`) |
| `app/Console/Commands/ReplicationLagCommand.php` | Monitoreo de lag de replicación PG (`logistics:replication:lag`) |
| `docs/ha-runbook.md` | Runbook operativo completo de HA |

### Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `routes/console.php` | Scheduler: queue health cada 5min, replication lag cada 5min, restore drill semanal |
| `docs/roadmap-alta-disponibilidad-escala.md` | Ola 4 marcada como IMPLEMENTADA con detalle |

### Componentes de la topología HA (`docker-compose.ha.yml`)

- **App**: `deploy.replicas: 2` + `restart_policy` con back-off + `DB_READ_HOST=pgbouncer-read`
- **postgres-standby**: hot standby con `pg_basebackup` + WAL streaming, volumen `postgres_standby_data`
- **pgbouncer-read**: PgBouncer dedicado al standby para read/write split
- **valkey-replica**: réplica con `--replicaof valkey 6379`
- **valkey-sentinel-1/2/3**: quórum de 2, `down-after-milliseconds: 5000`, YAML anchor `&sentinel`

### Uso

```bash
# Topología HA completa
docker compose -f docker-compose.yml -f docker-compose.ha.yml up -d

# Pre-deploy
docker compose exec app php artisan logistics:pre-deploy

# Monitoreo
docker compose exec app php artisan logistics:replication:lag
docker compose exec app php artisan logistics:queue:health
docker compose exec app php artisan logistics:restore-drill
```

## Decisión: ReadReplicaQueryBus eliminado

El decorador `ReadReplicaQueryBus` (Ola 3) llamaba a `useReadPdo()` que NO existe en
`PostgresConnection` de Laravel. Se eliminó porque **no es necesario**: Laravel ya rutea
SELECT→read y INSERT/UPDATE→write automáticamente cuando `read`/`write` están configurados en
`config/database.php` (Ola 3). CQRS separa los caminos por diseño.

- Eliminado: `packages/logistics/shared/src/Infrastructure/Bus/ReadReplicaQueryBus.php`
- Revertido: `SharedServiceProvider` registra `LaravelQueryBus` directo (sin decorador)

## Sesión del admin

Ya estaba en Valkey (`config/session.php` → `driver: redis`, conexión `session` DB 2). No se
necesitó cambiar nada para multi-instancia.
