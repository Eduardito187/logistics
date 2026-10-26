# Auditoría DevOps / Performance — plataforma logistics (2026-10-20)

**Alcance:** revisión integral (infra, base de datos, runtime, colas, HTTP, observabilidad, resiliencia,
seguridad operativa y escalado) con el objetivo declarado: **miles de usuarios concurrentes, millones de
registros, DB que crecerá a gigas en semanas.** Todo verificado contra el código/infra reales. Esta es
una **auditoría (no implementación)**: lista priorizada de recomendaciones para decidir qué atacar.

**Método:** inspección directa de `docker-compose.yml`, `.docker/*`, `config/*`, `routes/*` + dos
auditorías de código en paralelo (consultas/N+1; HTTP/Filament/colas/observabilidad/resiliencia).

> Contexto: el proyecto ya tiene una base **muy sólida** (índices de escala, BRIN, autovacuum por tabla,
> outbox con `SKIP LOCKED`+lease idempotente, rate limiting por identidad, cabeceras de seguridad, caché
> de catálogos, timeouts en todos los clientes externos, workers por cola vía supervisor, healthchecks de
> dos niveles, logs JSON, Sentry cableado). Lo de abajo es lo que **falta o escala mal**, no una enmienda
> a lo ya hecho. El cierre lista lo que está bien para no re-tocarlo.

---

## 🔴 CRÍTICO (atacar antes de subir carga real)

### C-1 — No hay backups ni PITR de PostgreSQL
No existe `pg_dump`, `pgBackRest`, `wal-g`, ni archivado de WAL en ningún lado (compose, `.docker`,
cron, Makefile). **Una base que crecerá a gigas sin estrategia de respaldo/restauración es el riesgo
#1.** Recomendación: pgBackRest o wal-g con **base + WAL archiving = PITR**, retención (p.ej. 7–30 días),
**y prueba de restauración automatizada** (un backup no probado no es un backup). Verificar cifrado y
destino off-site (S3/MinIO). Prioridad máxima.

### C-2 — Outbox capado a ~100 msg/min y con bloqueo de cabeza de línea
El drenaje real es `Schedule::command('logistics:outbox:relay')->everyMinute()->withoutOverlapping()`
(`routes/console.php`); `RelayOutboxBatch::handle()` despacha el I/O externo (SAP/FCM/email/SMS) **inline
y secuencial** con `OUTBOX_RELAY_LIMIT=100`. Las colas `outbox`/`notifications` tienen workers always-on
(`supervisord.conf`, `numprocs=2`) pero **nadie les encola** (los únicos `->onQueue()` van a `planning`).
Consecuencias a escala: techo de ~100 mensajes/minuto sin importar los workers, y un destino lento/caído
(10s de timeout c/u) hace que el lote supere 60s → `withoutOverlapping` salta el siguiente minuto →
backlog creciente. Contradice el objetivo (procesos transaccionales en paralelo).
**Recomendación:** que el relay **encole un job por mensaje/lote** a `outbox`/`notifications` (workers ya
existen), o convertir el relay en loop continuo multi-worker aprovechando el `SKIP LOCKED`+lease que YA
está. Separar colas por tipo de destino (SAP/FCM/email) para aislar el head-of-line blocking.

### C-3 — PostgreSQL con configuración por defecto + sin pooling de conexiones
`postgis/postgis:17` arranca con defaults (`shared_buffers≈128MB`, `work_mem≈4MB`,
`max_connections=100`, checkpoints/WAL mínimos); solo se monta un init de extensiones. No hay **PgBouncer**
ni conexiones persistentes (`REDIS_PERSISTENT=false`, nada para PG). Con PHP-FPM (`max_children=50`) ×
réplicas + workers de supervisor, **se agotan las 100 conexiones fácilmente** y cada conexión nueva es
cara. Recomendación: (a) **PgBouncer en transaction pooling** delante de PG; (b) tuning real por RAM
(`shared_buffers≈25%`, `effective_cache_size≈60–75%`, `work_mem`, `maintenance_work_mem`,
`max_wal_size`, `checkpoint_completion_target`, `max_connections` acorde a PgBouncer); (c) considerar
**réplica de lectura** para dashboards/reportes (CQRS ya separa lectura/escritura, encaja natural).

### C-4 — Migraciones de índices sin `CREATE INDEX CONCURRENTLY` (downtime en deploy)
0 ocurrencias de `CONCURRENTLY` en ~137 migraciones. Crear un índice normal toma `ACCESS EXCLUSIVE` y
**bloquea escrituras** mientras dura; sobre `telemetry_pings`, `tracking_*`, `outbox_messages`,
`shipments` a volumen, eso es downtime de escritura en pleno deploy (aplica también a las migraciones de
índice de las olas previas). Recomendación: para tablas grandes, `CREATE INDEX CONCURRENTLY` fuera de
transacción (`->withoutTransaction()` o SQL crudo) y revisar `ADD COLUMN` con default volátil.

---

## 🟠 ALTO

### A-1 — Dashboards: `COUNT(*)`/`GROUP BY` sin caché, con polling cada 30s
`PlatformStatsWidget` (~6 `COUNT(*)`/`GROUP BY status` sobre shipments, execution_deliveries, promises,
planning_plans, fleet_vehicles, outbox_messages), `OutboxHealthWidget`+`ReadsControlTowerData`,
`QueueHealthWidget`, `DeliveriesTodayWidget`, `FailedDeliveriesTodayWidget`, `NotificationsTodayWidget`,
`UnacknowledgedTelemetryAlertsWidget` — todos con `pollingInterval='30s'` y **sin `Cache::remember`**. En
Postgres `COUNT(*)` sin filtro es scan completo; con varios admins pollando sobre millones de filas es
carga sostenida pesada. Recomendación: `Cache::remember(...30–60s)` compartido (Redis ya está), o
contadores materializados mantenidos por el outbox, o estimación por `pg_class.reltuples` para los
"totales de un vistazo"; añadir `deferLoading` a tablas pesadas; `FleetCostDashboard::staleOdometerRows()`
(GROUP BY sobre `fleetops_odometer_readings` append-only + carga de todos los vehículos) igual.

### A-2 — Paginación cosmética: se materializa todo y se trocea en PHP
`app/Http/Support/ApiPagination.php` recibe una colección **ya materializada** y hace `array_slice`; es
**opt-in** (sin `?page/per_page/cursor` devuelve todo). Peor: el camino "arreglado" de shipments tiene el
reader con push-down (`DbShipmentListReader` soporta limit/offset) pero `ListShipments`/handler/controller
**no lo cablean** → el push-down es código muerto desde HTTP. Y **30+ endpoints `List*` no tienen
paginación**, alimentados por handlers que hacen `repo->all()` (access, carriers, fleet, fleetops,
network, telemetry trips/alerts, returns, settlements, inventory, inbound, etc.). A tablas de crecimiento
ilimitado esto tumba la plataforma. Recomendación: contrato de lectura paginado real (limit/offset o
**cursor por UUIDv7**) que baje a SQL en todos los `List*`, `per_page` por defecto (p.ej. 50) obligatorio
en tablas de crecimiento ilimitado, `COUNT(*)` separado/estimado. Empezar por cablear shipments (reader ya
listo).

### A-3 — Defaults de config a `database`, no a `redis`
`config/session.php`, `config/cache.php`, `config/queue.php` tienen el 2º arg de `env()` en `database`.
Si en prod **falta una env** (deploy nuevo, secreto no inyectado), sesiones/cache/colas caen
silenciosamente a PostgreSQL → contención brutal. Recomendación: default a `redis` en los tres, o validar
las envs críticas en el arranque (fail-fast).

### A-4 — Valkey único sin `maxmemory`/política de evicción, mezclando cache y datos durables
`valkey-server --appendonly yes` sin `maxmemory` ni `maxmemory-policy`. Una sola instancia sirve cache +
colas + sesiones + locks. Riesgos: crecimiento ilimitado hasta OOM; si se fija `maxmemory` con política
tipo `allkeys-lru`, **puede evictar jobs de cola o sesiones** (pérdida de datos). Recomendación: separar
**cache** (evictable, `volatile-lru`, sin AOF) de **colas/sesiones/locks** (durable, sin evicción, AOF)
en dos instancias o, como mínimo, DBs distintas con política segura y `maxmemory` dimensionado.

### A-5 — Sin circuit breaker por destino externo
Clientes con timeout (bien) pero **sin `->retry()` ni breaker**. Con SAP/FCM caídos, cada corrida del
relay reintenta todos los pendientes, cada uno agotando ~10s, y al ser secuencial frena a los destinos
sanos. Recomendación: breaker por destino (abrir tras N fallos, saltar rápido mientras esté abierto) +
cola por destino. Complementa C-2.

---

## 🟡 MEDIO

- **M-1 — Enriquecedores cargan la tabla completa.** `execution/.../Support/UserNames.php` hace
  `User::get(['id','name'])` (TODOS los usuarios) para etiquetar ~25 filas de una página; memoizado pero
  no acotado. A miles de usuarios, pesado. Resolver solo los ids visibles (`whereIn($pageIds)`) o cachear
  con TTL. (`VehiclePlates`/`NodeNames` son catálogos acotados, tolerables.)
- **M-2 — `whereDate()` sobre `timestamptz` anula el índice.** `EloquentTripRepository::listBy` y
  `TripResource` (`started_at`), `EmittedLabelResource` (`created_at`). Usar rango `>= día AND < día+1`.
  (`PromiseResource.slot_date` es DATE → inofensivo.)
- **M-3 — N+1 en Filament.** `ZoneResource` columna `serving_node` hace `DB::table('network_nodes')
  ->where('id',...)->first()` **por fila**. Usar el mapa memoizado `NodeNames::label()` como execution.
- **M-4 — Sin compresión HTTP.** nginx sin `gzip`/`brotli`. JSON de API y HTML/JS de Filament viajan sin
  comprimir. Activar gzip/brotli para `application/json`, `text/html`, `text/css`, `application/javascript`
  (o en el LB/CDN y documentarlo).
- **M-5 — Tracking público sin caché HTTP.** `PublicTrackingController` devuelve JSON sin
  `Cache-Control`/`ETag`; es la página que los clientes pollean → cada hit pega a la DB. Añadir
  `Cache-Control: public, max-age=15–30` y/o ETag para que CDN/navegador absorban el polling.
- **M-6 — Sin métricas / tracing / slow-query.** No hay `/metrics` Prometheus, ni OpenTelemetry, ni
  `pg_stat_statements`, ni log de queries lentas, y `SENTRY_TRACES_SAMPLE_RATE` viene vacío (tracing
  apagado). Para operar a escala hace falta RED/USE: latencia p95/p99, tasa de error, **profundidad de
  colas y lag del outbox**, dead-letter. Habilitar `pg_stat_statements` + slow query log (>200–500ms).
- **M-7 — Correlación de request-id no end-to-end.** Default `UidProcessor` (id por proceso); no toma
  `X-Request-Id` entrante ni lo propaga por evento→outbox→relay→notificación. El hook ya existe
  (`LOG_REQUEST_ID_PROCESSOR`); implementarlo y arrastrar el id en el `context` y en los mensajes.
- **M-8 — `outbox_messages` sin poda programada.** El comando `logistics:outbox:prune` existe (ola 2)
  pero **no está en el scheduler** (quedó a decisión del dueño por ser borrado). Sin activarlo, la tabla
  crece y agrava el `GROUP BY` de OutboxHealthWidget. Activar con retención revisada + backup previo.
- **M-9 — Dockerfile prod sin `STOPSIGNAL`/`HEALTHCHECK`.** Docker manda SIGTERM y PHP-FPM lo toma como
  apagado inmediato (corta requests en vuelo). Usar `STOPSIGNAL SIGQUIT` (drenado graceful de FPM) +
  readiness del orquestador apuntando a `/api/v1/health` antes de enrutar tráfico.
- **M-10 — Maintenance mode driver `file`.** Con varias réplicas web el `down` es por-contenedor. Usar
  driver `cache` (Redis) para modo mantenimiento cluster-wide.
- **M-11 — `Device::find()` por request en la puerta `/app/*`.** `EnsureDeviceToken` lee el device por
  PK en cada request. A miles de req/s, cachear el estado "device vigente" en Redis (TTL corto, invalidar
  al revocar).
- **M-12 — Sin límites de CPU/memoria en compose.** Ningún servicio declara `deploy.resources`/`mem_limit`
  → sin aislamiento (noisy neighbor) ni predictibilidad. Definir límites por servicio (en el manifiesto de
  prod/orquestador).
- **M-13 — Sin rotación de logs de contenedor.** Compose sin `logging: {driver: json-file, options:
  {max-size, max-file}}` → los logs de contenedor crecen sin tope en disco. Configurar rotación (o
  shipping a Loki/ELK, que el canal JSON ya soporta).
- **M-14 — Índices faltantes puntuales.** `returns`: filtra `status` + ordena `created_at` sin índice →
  `(status, created_at)`. `inbound`: `orderBy('appointment_start')` sin índice (baja). `promise`:
  `where('from_node_id')` sin índice propio (verificar si es caliente).

---

## 🟢 BAJO / higiene

- **B-1 — Timeouts desalineados:** `max_execution_time=60` (PHP) vs `fastcgi_read_timeout 300s` (nginx);
  nginx espera 300s por un worker ya muerto a los 60s. Alinear.
- **B-2 — `connectTimeout` ausente** en SAP, Magento, FCM, Telegram, SMS (solo fijan `->timeout()`); un
  connect colgado consume el timeout total. Añadir `->connectTimeout(3–5s)` como OSRM.
- **B-3 — CORS sin publicar** (`config/cors.php` ausente → default `allowed_origins: *`). Riesgo bajo
  (API por token), pero acotar explícitamente los orígenes de la puerta `channels`.
- **B-4 — Compose dev expone PG (5442) y Valkey (6409) al host.** Es dev; verificar que el manifiesto de
  prod no publique esos puertos.
- **B-5 — `BROADCAST_CONNECTION=log` por defecto:** el mapa de flota en vivo no emitirá por WebSocket en
  prod salvo configurar reverb/pusher/soketi.
- **B-6 — CI sin `composer audit`/`npm audit`.** Añadirlos como gate de seguridad de dependencias.
- **B-7 — CSP con `unsafe-inline`+`unsafe-eval`** (requisito de Alpine/Livewire de Filament): inherente,
  anotado, aceptable.
- **B-8 — GIN/jsonb:** hoy no se filtra por contenido jsonb en runtime (solo en un seeder) → no hace
  falta GIN aún; agregarlo si alguna consulta caliente empieza a filtrar dentro de un jsonb.

---

## Mirada a futuro (escala sostenida)

- **Particionado por tiempo** de `telemetry_pings`/`execution_scan_events` (diseño ya en
  `docs/perf/particionado-tablas-giga.md`): ejecutar por `ATTACH PARTITION` + retención por `DROP
  PARTITION` cuando lleguen a decenas de millones.
- **Réplica(s) de lectura** para dashboards/reportes/listados pesados (CQRS ya lo habilita).
- **CDN** para assets de Filament y tiles de mapa; **autoescalado horizontal** de web y workers (la app ya
  es stateless: sesión/cache/colas en Redis).
- **Rollup/downsampling** de telemetría (pings → agregados) para reportes históricos baratos.

---

## Prioridad recomendada (orden de ejecución)

1. **C-1 backups/PITR** + **C-3 PgBouncer/tuning PG** — fundamentos de datos antes de subir carga.
2. **C-2 throughput del outbox** (alimentar workers / loop multi-worker) — el cuello asíncrono real.
3. **C-4 índices CONCURRENTLY** — para que los deploys no bloqueen.
4. **A-1 caché de KPIs** + **A-2 paginación real a SQL** — lo que más pega en latencia de usuario.
5. **A-3 defaults redis** + **A-4 Valkey maxmemory/separación** + **A-5 circuit breaker**.
6. **M-6 observabilidad** (métricas de cola/lag outbox, pg_stat_statements, tracing) — para ver el resto.
7. Resto de MEDIO/BAJO como higiene continua.

## Lo que ya está excelente (no re-tocar)
Outbox `SKIP LOCKED`+lease idempotente; índices de escala + BRIN + autovacuum por tabla; rate limiting por
identidad; `SecurityHeaders` (CSP/HSTS/nosniff/Referrer-Policy); caché de catálogos (forma primitiva,
regla 7); timeouts en todos los clientes externos + degradación elegante (OSRM→haversine,
Nominatim→Null, SAP/Magento→stub); workers por cola con drain graceful (supervisor); `retry_after >
timeout` en todas las colas; healthchecks `/up` + `/api/v1/health`; logs JSON + Sentry; idempotencia de
requests offline; `DbShipmentListReader` (sin N+1); `geocoding_cache` (dedup); `config/route/view/event:
cache` opt-in en arranque; `AUTO_MIGRATE=false` + `queue:restart` en deploy.
