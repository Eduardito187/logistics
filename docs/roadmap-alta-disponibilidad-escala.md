# Roadmap — Alta disponibilidad, concurrencia y eficiencia de CPU

> **Para**: el dueño del proyecto y el equipo que ejecuta en este repo.
> **Objetivo del dueño (2026-11-13)**: soportar **~2.000 clientes simultáneos** con **alta disponibilidad**,
> **mínimo consumo de CPU** y **escalabilidad horizontal**. Este documento es el plan por olas para llegar ahí.
>
> Complementa (no reemplaza) a `docs/roadmap-estructura-escalabilidad.md` (que mira la escala de DATOS, a
> millones de registros). Acá el foco es el **runtime**: concurrencia, latencia, CPU y disponibilidad.

---

## 1. Objetivo y supuestos

- **Concurrencia meta**: 2.000 clientes simultáneos. Ojo con el término: 2.000 *usuarios conectados* no son
  2.000 *requests por segundo*. Con "think time" típico (un usuario hace 1 request cada 5-15 s), 2.000 usuarios
  ≈ **150-400 RPS sostenidos**, con picos. Diseñamos con headroom para **~800-1.000 RPS pico**.
- **Disponibilidad meta**: **99,9 %** (≈ 43 min de caída al mes). Implica: sin punto único de falla, deploys
  sin downtime, failover automático de DB y caché.
- **CPU mínima**: el mayor costo de CPU hoy es **arrancar el framework en cada request** (FPM). La palanca #1
  es mantenerlo en memoria (Octane). Lo demás: menos trabajo por request (caché, menos queries, payloads chicos).
- **Escalable**: el tier de app debe ser **stateless** para sumar réplicas sin coordinación; la DB escala con
  réplicas de lectura (CQRS lo habilita) y particionamiento; cada bounded context es extraíble a servicio (ADR
  001/008) cuando el volumen lo justifique.

## 2. Qué YA tenemos a favor (no reinventar)

- **Monolito modular DDD/CQRS/Clean**: lectura por `QueryHandler`/ReadModels separada de la escritura →
  habilita mandar las LECTURAS a réplicas sin tocar el dominio.
- **PgBouncer** en transaction pooling → multiplexa miles de conexiones de cliente sobre un pool chico.
- **Valkey** para caché y colas.
- **Outbox transaccional** idempotente con `FOR UPDATE SKIP LOCKED` + lease → ya diseñado para **N workers en
  paralelo** sin pisarse. Nada de I/O externo síncrono en el request.
- **Migraciones expand/contract** + `AUTO_MIGRATE` en job de deploy → base para deploys sin downtime.
- **Health real + autoheal** (readiness ≠ liveness) y workers con `restart: unless-stopped`/supervisord.
- **SlowQueryListener**, índice parcial del outbox, caché de referencia en Valkey, paginación opt-in
  (`ApiPagination`), read-side SQL que selecciona solo lo necesario.
- **UUID v7** (índices compactos, ordenables) y regla CERO CDN runtime (todo self-host).

## 3. Principios que guían el roadmap

1. **Menos trabajo por request** antes que más máquinas: caché, menos queries, framework en memoria.
2. **Stateless app tier**: cualquier instancia atiende cualquier request → escalar = sumar réplicas.
3. **Leer de réplicas, escribir en el primario** (CQRS ya separa los caminos).
4. **Degradar, no caer**: readiness/liveness, circuit breakers, rate limiting, colas que absorben picos.
5. **Medir antes de optimizar**: sin métricas y load testing no se sabe dónde está el cuello.
6. **Async por defecto**: todo I/O externo por outbox; el request del usuario termina rápido.

---

## 4. Roadmap por olas

Orden recomendado: cada ola habilita la siguiente. Las olas 1-2 son el mayor retorno de CPU; la 3-4 dan la HA y
la escala real; la 5-6 sostienen la operación a escala.

### Ola 1 — Quick wins de CPU y latencia (bajo riesgo, alto retorno)

- **OPcache + preload afinados**: `opcache.validate_timestamps=0` en prod (ya hay preload opt-in; activarlo con
  el classmap del monolito). Elimina recompilar PHP en cada request.
- **PHP-FPM tuning**: `pm=static` dimensionado a la RAM real (cada worker ~40-80 MB), `pm.max_requests` para
  reciclar y no arrastrar fugas. Medir `max_children` contra la memoria del host, no al voleo.
- **Compresión en nginx** (gzip/brotli) para respuestas JSON y assets + HTTP/2 + keep-alive. Baja payload y CPU
  de red del cliente.
- **Cache-Control agresivo** para assets versionados (hash de Vite) servidos por nginx (self-host, respeta CERO
  CDN). ETags / `304 Not Modified` en los endpoints de lectura del app (listas estables) → menos payload y CPU.
- **Caché de los cockpits/Control Tower**: las agregaciones caras (KPIs, torre de control) a Valkey con TTL
  corto + **protección anti-stampede** (lock por clave). Hoy se recalculan por request; son el gasto de CPU más
  visible en el admin.
- **Rate limiting por token/cliente** (throttle de Laravel sobre Valkey): protege de abuso y suaviza picos antes
  de que lleguen a la DB. Límites por endpoint sensible (POD, escaneos, ingesta).
- **Barrido de N+1 e índices faltantes**: usar `SlowQueryListener` + `EXPLAIN` sobre los endpoints calientes del
  app (ruta del chofer, tracking, etiquetas) y agregar los índices que falten (parciales donde aplique, como ya
  se hizo con el outbox). Eager-load explícito, `select` de columnas mínimas.
- **Paginación por cursor** en las listas grandes (envíos, pedidos, scans) — más barata y estable que offset a
  escala; la paginación opt-in ya existe, migrar las listas calientes a cursor.

### Ola 2 — Laravel Octane (la palanca #1 de CPU/throughput)

> Nota: hasta ahora Octane estaba marcado "no-ahora" ([[perf-scale-and-mail]]). Con la meta explícita de 2k
> concurrentes y mínima CPU, **se vuelve prioritario**: mantener el framework booteado en memoria baja el costo
> de CPU por request ~5-10x y multiplica el throughput con menos workers.

- **Elegir runtime**: **FrankenPHP** (integra con el stack PHP actual, worker mode, HTTP/2/3, fácil de dockerizar)
  o Swoole/RoadRunner. FrankenPHP es el camino de menor fricción para este stack.
- **Gotchas de estado (críticos)**: con el framework en memoria, los **singletons y estado estático persisten
  entre requests**. Auditar: servicios que guardan estado por request, el `HandlerRegistry` del bus CQRS,
  configuraciones mutadas en runtime, `Auth`/request scoping. Resetear lo que haga falta entre requests.
- **Fugas de memoria**: `--max-requests` para reciclar workers; vigilar memoria por worker.
- **Conexiones persistentes**: con Octane las conexiones a DB/Valkey viven entre requests → cuidar el gotcha de
  **PgBouncer transaction mode + prepared statements** ([[pg-pgbouncer-gotchas]]): emular prepares por default.
- **Plan de adopción**: correr Octane primero en el tier del **app API** (stateless, token-based, el de mayor
  volumen), dejar el admin Filament en FPM al principio (sesión + estado más delicado), migrarlo después con los
  singletons auditados. Load test comparativo FPM vs Octane antes de cortar.

### Ola 3 — Base de datos a escala (el cuello real a 2k)

- **Réplicas de lectura + routing CQRS**: configurar `read`/`write` connections de Laravel; que los
  `QueryHandler`/ReadModels usen la conexión de **réplica** y los `CommandHandler` el **primario**. CQRS hace
  esto limpio (los caminos ya están separados). Escala las lecturas horizontalmente y descarga el primario.
- **Particionamiento de tablas calientes** por rango temporal: `execution_scan_events`, `outbox_messages`,
  telemetry pings, `shipments`/`tracking` históricos. Índices más compactos, VACUUM más barato, archivado/purga
  por partición (los comandos de prune ya existen, habilitarlos por retención).
- **ReadModels materializados / denormalizados** para dashboards pesados (Control Tower): vistas materializadas
  o tablas de proyección refrescadas por el outbox, no agregaciones en vivo.
- **PgBouncer**: dimensionar `default_pool_size`/`max_client_conn` para 2k clientes; considerar varias instancias
  de PgBouncer detrás del LB para no ser punto único.
- **Afinar VACUUM/ANALYZE** (ya automatizado) y `work_mem`/`shared_buffers`/`effective_cache_size` al host real.

### Ola 4 — Alta disponibilidad (sin punto único de falla)

- **App tier multi-instancia** (stateless) detrás de un **load balancer** con health checks → una instancia caída
  no corta el servicio; escalar = sumar instancias. Sesión del admin en Valkey (no en archivo local).
- **PostgreSQL HA**: primario + standby con **replicación en streaming** y **failover automático** (Patroni /
  repmgr, o Postgres gestionado con HA). La app apunta al endpoint virtual, no a una IP fija.
- **Valkey HA**: **Sentinel** (o cluster) para failover de la caché/colas. La caché no debe ser punto único.
- **Deploy sin downtime**: ya hay expand/contract + `queue:restart`; sumar **rolling deploy** del tier de app
  (recrear instancias de a una, drenar conexiones), recarga graceful de FPM/Octane, y el `schedule:clear-cache`
  en boot ([[outbox-scheduler-overlap-lock]]) ya evita que el outbox se congele tras un deploy.
- **Workers resilientes**: supervisord ya reinicia; sumar **autoscaling** de workers por profundidad de cola.
- **Backups + restore probados** (ya existe `backup:create/verify/restore`): ensayar el restore como parte de la
  HA, no solo tenerlo.

### Ola 5 — Observabilidad, load testing y SLOs (operar a escala)

- **Métricas (Prometheus)**: latencia p50/p95/p99 por endpoint, RPS, profundidad de cada cola, lag del outbox,
  conexiones de DB en uso, hit rate de Valkey, lag de réplica, salud de workers.
- **Tracing (OpenTelemetry)** en los caminos lentos (ingesta, planning, cockpits).
- **Logging estructurado** centralizado (ya hay `SlowQueryListener`); dead-letter del outbox visible con alerta.
- **Alerting** sobre: backlog de colas, lag del outbox, lag de réplica, tasa de 5xx, saturación de CPU/conexiones.
- **SLOs**: definir p95 de latencia por tipo de request y el target de disponibilidad; medir contra ellos.
- **Load testing a 2k** (k6/Gatling/Locust) con un escenario realista (login, ver ruta, POD, tracking, admin):
  **encontrar el cuello ANTES de prod**. Repetir tras cada ola para validar el retorno.

### Ola 6 — Escalar horizontalmente / extraer contextos calientes

- Cuando el volumen lo pida (lo dicen las métricas, no la intuición), **extraer el bounded context más caliente**
  a un servicio independiente (ADR 001/008): candidatos probables = **telemetry** (ingesta de pings GPS, write
  pesado) y/o el **read-side de tracking** (muy consultado por los clientes). El outbox/CQRS ya puede ir sobre
  transporte de red; es mover el package, no reescribir.
- **Ingesta de telemetría** como camino dedicado (cola propia, posible almacenamiento time-series o partición
  agresiva) para que los pings no compitan con el request del usuario.
- **Edge/caché de lectura** del tracking público (el enlace que ve el cliente final) con TTL corto — alto tráfico,
  datos poco cambiantes.

---

## 5. Riesgos y orden recomendado

- **Primero medir**: montar métricas básicas + un load test (parte de la Ola 1/5) antes de invertir en Octane o
  réplicas, para optimizar donde duele y poder comparar.
- **Octane es el mayor salto pero el de más riesgo** por el estado persistente: hacerlo con auditoría de
  singletons y un canario (app API primero), no de golpe en todo.
- **HA de DB** es lo que de verdad da el 99,9 %: sin failover de Postgres, todo lo demás es secundario.
- **No partir a microservicios antes de tiempo** (ADR 001): el monolito modular + réplicas + Octane + HA alcanza
  holgadamente los 2k concurrentes. La Ola 6 es solo cuando una métrica concreta lo justifique.

**Secuencia sugerida**: Ola 1 (quick wins + primeras métricas) → Ola 5 parcial (métricas + load test base) →
Ola 2 (Octane en app API) → Ola 3 (réplicas + particionamiento) → Ola 4 (HA completa) → Ola 5 completa → Ola 6
cuando el volumen lo pida.
