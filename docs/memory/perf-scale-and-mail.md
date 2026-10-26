---
name: perf-scale-and-mail
description: "Optimización a gran escala (caché Valkey, read-side SQL, índices outbox, FPM/Octane) y email por sendmail nativo (Mailpit eliminado)"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-20T06:02:03.132Z
---

Optimización de performance para miles de usuarios concurrentes (hecha 2026-10-20) y cambio de email.

**Email / Mailpit:** Mailpit eliminado al 100% (servicio+volumen de compose, env, docs). El email va por
el **sendmail nativo del host** (`MAIL_MAILER=sendmail`, `MAIL_SENDMAIL_PATH`), sin SMTP propio ni
catcher. `EmailChannelSender` sin cambios (usa el mailer de Laravel). No reintroducir mailpit.

**Caché de datos de referencia (Valkey):** patrón decorador (guarda forma primitiva array, invalida en
save/remove, TTL de red) replicado del de `promise`/`capacity` en: network (nodes/legs), coverage
(zones/geo_areas/municipalities), notifications (templates/type_rules), sap-connector (material_mappings),
settings (GetSetting "a ahora"). Keys con prefijo por contexto. NO se cachea render por-notificación ni
resolución espacial PostGIS. Ver [[api-readmodel-enrichment]].

**Read-side shipments:** `ListShipments`/`ListLowConfidenceAddresses` ya NO cargan toda la tabla; usan
`ShipmentListReader` → `DbShipmentListReader` (filtros a SQL, sin reconstruir el agregado). Contrato de
`ShipmentView` idéntico. Gotcha arreglado: `ShipmentRecord::lines()/units()` ahora llevan `->orderBy('id')`
(antes orden de heap no determinista → fallaba la paridad solo en la suite completa). Ver [[dispatch-calendar-municipality]].

**Índices/concurrencia:** el relay del outbox (`EloquentOutboxStore::pullBatch`: `FOR UPDATE SKIP LOCKED
... WHERE status IN ('pending','failed') ORDER BY available_at LIMIT n`) está respaldado por un índice
**parcial** `(available_at) WHERE status IN ('pending','failed')` (sin nodo Sort, verificado con EXPLAIN).
Más índices en execution/telemetry/carriers/fleetops. Todas las migraciones aditivas e idempotentes.

**Mantenimiento/retención de DB (ola 2, 2026-10-20):** comando `logistics:db:maintain` (VACUUM/ANALYZE
de tablas calientes, allowlist, nunca VACUUM FULL) **ya programado** en routes/console.php (03:30 + hourly
analyze-only). BRIN en `telemetry_pings(recorded_at)` y `execution_scan_events(occurred_at)` (btree
conservado); autovacuum agresivo por tabla. Motor de poda chunked en `shared` (`RowPruner`/`PruneSpec`) +
3 comandos `logistics:{access:prune-audit-log,outbox:prune,telemetry:prune-pings}` — **dry-run por
defecto**, solo borran con `--force`, retención por env (ACCESS_AUDIT_RETENTION_DAYS=180,
OUTBOX_PRUNE_DISPATCHED_DAYS=14/DEAD=90, TELEMETRY_PINGS_RETENTION_DAYS=60). Los crons de purga NO están
programados: el clasificador bloqueó el --force automático; el dueño debe habilitarlos. NUNCA podar
negocio (scan_events/deliveries/positions en vivo). Dedup de geolocalizaciones: tabla `geocoding_cache` +
decorador `CachingGeocoder` sobre el `Geocoder` de shipments (hash de dirección normalizada, cuenta hits).
Particionado de tablas giga = diseñado en `docs/perf/particionado-tablas-giga.md`, NO ejecutado (ATTACH
PARTITION cuando crezca, con backup). Reporte: `docs/perf/REPORTE-optimizacion-db-2026-10-20.md`.

**Endurecimiento DevOps a escala (ola 3, 2026-10-20):** auditoría completa en
`docs/perf/AUDITORIA-devops-performance-2026-10-20.md` → corregido en 7 workstreams. Infra (`docker-compose.yml`
+ `.docker/**`): **PgBouncer** (servicio `pgbouncer:6432`, transaction pooling, comodín atiende logistics y
logistics_test; la app conecta vía pgbouncer, requiere `DB_EMULATE_PREPARES=true`; conexión `pgsql_direct`
para migraciones), **tuning Postgres** (`PG_*`) + `pg_stat_statements`, **backups** servicio `pg-backup`
(pg_dump -Fc diario a volumen `backups`, restore probado; PITR/off-site documentado en
`.docker/backups/README.md`, NO ejecutado), **Valkey** `--maxmemory`+`volatile-lru`, nginx **gzip**,
Dockerfile `STOPSIGNAL SIGQUIT`+HEALTHCHECK, límites de recursos y override de puertos en
`.docker/compose/docker-compose.prod.yml`, rotación de logs, timeouts alineados. App: defaults
session/cache/queue→**redis** + separación de DBs redis (0 colas/1 cache/2 sesión), CORS acotado, middleware
`RequestId` end-to-end, maintenance store redis. **Outbox throughput**: `logistics:outbox:pump` (encola 1 job
por mensaje a colas outbox/notifications → despacho paralelo; reemplazó al relay inline capado a ~100/min;
el `relay` sincrónico queda como fallback) + **circuit breaker** por destino/canal en Valkey
(`OUTBOX_BREAKER_*`) + connectTimeout(3s) en todos los clientes HTTP. **Paginación real a SQL** (offset
page/per_page + cursor) con envelope `{data,meta}` en ~11 List* (shipments/telemetry/returns/fleetops/
settlements/inventory/inbound/notifications); ReadModel `Logistics\Shared\Application\ReadModel\Page`.
**Filament**: KPIs de ControlTower cacheados (Redis TTL 30s), `UserNames`/N+1 ZoneResource/whereDate
corregidos. **Observabilidad**: endpoint `/api/metrics` Prometheus (protegido por `METRICS_TOKEN`/allowlist,
404 si no), slow-query log opt-in, comando `logistics:db:slow-queries`, CI con composer/npm audit.
Device cache en `EnsureDeviceToken`, cache HTTP (ETag) en tracking público, índice `returns(status,created_at)`
CONCURRENTLY (convención en `docs/perf/indices-concurrently.md`). Verde: PHPStan 8, Pint, Pest 2120 passed,
cobertura 81.7%. Pendiente de decisión del dueño: activar crons de purga `--force`, PITR off-site, y el
`queue-worker` dev ya drena todas las colas.

**Runtime:** PHP-FPM parametrizable por env (`FPM_*` en .env.example, defaults horneados en
`.docker/php/www.conf`; el entrypoint renderiza desde `.docker/php/www.conf.template` porque php-fpm no
interpola env en directivas numéricas). OPcache/JIT ya estaban óptimos. Preload = `bootstrap/preload.php`
OPT-IN **desactivado** (auditar providers + load test antes de activar). Octane/FrankenPHP evaluado:
**no adoptar ahora** (ver `docs/perf/octane-frankenphp-evaluacion.md`); FPM+OPcache/JIT ya alcanza.
Reporte completo: `docs/perf/REPORTE-optimizacion-escala-2026-10-20.md`.
