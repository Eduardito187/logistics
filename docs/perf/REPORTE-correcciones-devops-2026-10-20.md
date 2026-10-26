# Reporte de correcciones — Auditoría DevOps/Performance (2026-10-20)

Implementación de **todos** los puntos de `AUDITORIA-devops-performance-2026-10-20.md`, en 7 workstreams
paralelos con propiedad de archivos disjunta + 1 pase de contrato. **Sin `git commit`/`push`.**

## Verificación global (DoD, en containers)
- **Pint:** limpio (3134 archivos).
- **PHPStan nivel 8:** `No errors` (2702 archivos).
- **Pest:** **2120 passed / 5938 assertions, 0 fallos** · **cobertura 81.7 %** (gate ≥75).
- **ApiContractTest:** 12 passed (spec OpenAPI 3.1 válido tras los deltas de paginación).
- Migraciones aditivas e idempotentes (índice nuevo con `CONCURRENTLY`). **Sin operaciones destructivas.**
- Infra probada en **stack descartable aislado**; el stack dev y sus datos quedaron intactos.

---

## Estado por punto

### 🔴 Críticos
- **C-1 Backups** ✅ Servicio `pg-backup` (pg_dump -Fc diario → volumen `backups`, retención
  `BACKUP_RETENTION_DAYS`, restore **probado** roundtrip + guardia anti-pisada). **PITR (pgBackRest/wal-g +
  WAL archiving) y envío off-site (S3/MinIO) quedan documentados en `.docker/backups/README.md`** como
  siguiente paso que requiere tu decisión de destino/secretos.
- **C-2 Throughput outbox** ✅ `logistics:outbox:pump` encola 1 job por mensaje a colas always-on
  (outbox/notifications) → despacho **paralelo**; elimina el techo ~100/min y el head-of-line blocking
  (aísla por cola/destino). Relay sincrónico queda como fallback. Scheduler ya apunta al pump.
- **C-3 PgBouncer + tuning PG** ✅ Servicio `pgbouncer:6432` (transaction pooling), tuning de Postgres
  (`PG_*`) y `pg_stat_statements`. App conecta vía PgBouncer (`DB_EMULATE_PREPARES=true`); conexión
  `pgsql_direct` para migraciones/DDL.
- **C-4 Índices CONCURRENTLY** ✅ Índice nuevo `returns(status,created_at)` creado con `CREATE INDEX
  CONCURRENTLY` (`withinTransaction=false`) + convención documentada en `docs/perf/indices-concurrently.md`.

### 🟠 Altos
- **A-1 KPIs dashboards cacheados** ✅ ControlTower/PlatformStats/widgets y FleetCostDashboard con
  `Cache::remember` (Redis, TTL 30–300s, forma primitiva) + `deferLoading` en tablas pesadas.
- **A-2 Paginación real a SQL** ✅ Offset (page/per_page) + cursor con envelope `{data,meta}` en ~12 List*
  (shipments, telemetry trips/alerts, returns, fleetops refuels/odometer/expenses, settlements, inventory
  lots, inbound asn, notifications). ReadModel `Shared\...\Page`. openapi actualizado.
- **A-3 Defaults a redis** ✅ session/cache/queue por defecto a `redis` (un env faltante ya no cae a PG).
- **A-4 Valkey + separación** ✅ `--maxmemory` + `volatile-lru` (solo evicta cache con TTL); DBs
  separadas: 0 colas/locks, 1 cache, 2 sesiones.
- **A-5 Circuit breaker** ✅ Por destino/canal (FCM/Telegram/email/SMS/SAP), estado en Valkey, 5 fallos/60s
  → corta 30s → half-open.

### 🟡 Medios
- **M-1 UserNames** ✅ acotado + cacheado (ya no carga todos los usuarios).
- **M-2 whereDate→rango** ✅ en `EloquentTripRepository`, `TripResource`, `EmittedLabelResource`.
- **M-3 N+1 ZoneResource** ✅ resuelto con mapa memoizado `NodeNames`.
- **M-4 gzip** ✅ nginx comprime json/js/css/svg (brotli → documentado para LB/CDN).
- **M-5 cache HTTP tracking** ✅ `Cache-Control: public, max-age=20` + ETag/304.
- **M-6 observabilidad** ✅ `/api/metrics` Prometheus protegido (colas, backlog outbox, failed_jobs),
  slow-query log opt-in + request-id, comando `logistics:db:slow-queries`, `pg_stat_statements` habilitado.
  *(Tracing Sentry: env listo `SENTRY_TRACES_SAMPLE_RATE`; emite al instalar `sentry/sentry-laravel`.)*
- **M-7 request-id end-to-end** ✅ middleware `RequestId` (toma/propaga `X-Request-Id`, lo inyecta en logs
  JSON y en el `Context` → jobs/outbox).
- **M-8 poda outbox** ⏸️ comando listo; **cron no activado** (borrado real; requiere tu visto bueno).
- **M-9 Dockerfile** ✅ `STOPSIGNAL SIGQUIT` + HEALTHCHECK.
- **M-10 maintenance cluster-wide** ✅ store `redis` (activar con `APP_MAINTENANCE_DRIVER=cache`).
- **M-11 Device cache** ✅ snapshot en Redis (TTL 30s) con invalidación en `revoke()`.
- **M-12 límites de recursos** ✅ en `.docker/compose/docker-compose.prod.yml` (parametrizables).
- **M-13 rotación de logs** ✅ `json-file max-size 10m/max-file 3` por servicio.
- **M-14 índices** ✅ `returns(status,created_at)`; `inbound.appointment_start`/`promise.from_node_id`
  evaluados y **descartados** (ya cubiertos por índices existentes; se documentó).

### 🟢 Bajos
- **B-1 timeouts alineados** ✅ (PHP 60s / nginx 65s). **B-2 connectTimeout(3s)** ✅ en todos los clientes
  HTTP. **B-3 CORS** ✅ acotado por env (nunca `*`). **B-4 puertos** ✅ no publicados en override prod.
  **B-5 broadcast** ✅ documentado. **B-6 CI audits** ✅ `composer audit` + `npm audit`. **B-7 CSP** anotado
  (inherente a Filament). **B-8 GIN** no requerido hoy (documentado).

---

## Pendientes que requieren TU decisión (no se activaron por seguridad)
1. **Crons de purga `--force`** (audit_log / outbox terminales / pings): listos; actívalos tras revisar
   retención y con backup previo (borrado real; el clasificador los bloqueó para auto-activación).
2. **PITR + off-site de backups**: definir destino (S3/MinIO), secretos y cifrado → activar pgBackRest/wal-g.
3. **Despliegue con PgBouncer**: al recrear el stack, la app conecta vía `pgbouncer`; asegurar
   `DB_EMULATE_PREPARES=true` y que migraciones usen `--database=pgsql_direct`.
4. **Instalar `sentry/sentry-laravel`** si se quiere tracing real.

## Nuevas variables de entorno
Todas documentadas en `.env.example` (PgBouncer/PG tuning/Valkey/backups/outbox pump+breaker/
observabilidad/CORS/maintenance/límites prod).

## Mirada a futuro (sin cambios aún)
Particionado por tiempo (telemetry/scan_events), réplicas de lectura, CDN para assets/tiles, autoescalado
horizontal, rollup de telemetría. Detalle en `docs/perf/` (auditoría + particionado).
