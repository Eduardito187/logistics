# Observabilidad — logistics

Guía base de observabilidad de la plataforma: logs estructurados, métricas a exponer, trazabilidad
de la cadena asíncrona, spans livianos y cómo enchufar Sentry u OpenTelemetry. Es la referencia de
operación; la configuración vive en `config/observability.php`, `config/logging.php` y
`config/sentry.php`, y las envs en `.env.example`.

Principio rector: la plataforma procesa eventos de forma asíncrona por outbox (ADR 002, ADR 008) y
corre sobre colas (Valkey/Redis). Por eso la observabilidad se centra en **el flujo de eventos y las
colas**, no solo en el request HTTP.

## 1. Logs estructurados (JSON)

El canal `json` de `config/logging.php` emite **una línea JSON por evento**, pensado para que el
agregador (Loki, CloudWatch Logs, ELK) lo ingiera sin parsear texto libre. Cada registro lleva
`message`, `level`, `channel`, `datetime`, `context` y `extra`.

- En desarrollo se mantiene `LOG_STACK=single` (texto legible).
- En producción se recomienda `LOG_STACK=json` y escribir a `php://stderr` (`LOG_JSON_STREAM`), para
  que el runtime/contenedor recoja el stream y lo mande al agregador.

### Correlación (`request_id`)

El middleware `App\Http\Middleware\AssignRequestId` es el **primero de la pila** (`bootstrap/app.php`):

- Toma el `X-Request-Id` entrante si es un token seguro (`[A-Za-z0-9._-]`, hasta 128 caracteres); si
  falta o no valida, genera un **UUID v7**.
- Lo guarda en el `Context` de Laravel (`Illuminate\Support\Facades\Context`) bajo `request_id`.
- Lo devuelve en la cabecera `X-Request-Id` de la respuesta.

A partir de ahí la propagación es del framework, sin código propio:

- **Logs:** Laravel agrega el Context al `extra` de cada registro de **cualquier** canal. En el canal
  `json` aparece como `extra.request_id` (y también como `context.request_id`, por
  `Log::withContext`). No hace falta ningún processor adicional; el de `LOG_REQUEST_ID_PROCESSOR`
  (`App\Http\Middleware\RequestId`, que lee la misma clave del Context) se conserva por compatibilidad.
- **Jobs encolados:** el Context se serializa en el payload del job y se restaura en el worker. El
  mismo `request_id` aparece en los logs del relay del outbox, de las notificaciones y de cualquier
  job que haya nacido de ese request.
- **Consultas lentas y spans:** `SlowQueryListener` y `Span` (sección 5) lo incluyen en su línea.

Regla de oro: **todo log de un job o de un listener de evento incluye en su `context` el
`request_id`/`event_id` que originó el trabajo.** Es lo que permite reconstruir la traza completa
(sección 3).

## 2. Métricas

### 2.1 Métricas reales expuestas en `GET /api/metrics`

Formato de texto de Prometheus (0.0.4). El endpoint está protegido por `METRICS_TOKEN`
(`Authorization: Bearer` o `X-Metrics-Token`) y/o `METRICS_IP_ALLOWLIST`; sin ninguno responde 404.
Lo arma `App\Metrics\MetricsCollector`. Cada sonda es barata y tolerante: si su dependencia falla, la
serie sale con su valor neutro y el scrape no se rompe.

**Tráfico HTTP (RED)** — acumulado en Valkey por `App\Http\Middleware\RecordRequestMetrics`:

| Métrica | Tipo | Etiquetas | Qué mide |
| --- | --- | --- | --- |
| `logistics_http_requests_total` | counter | `route`, `method`, `status`, `gate` | Requests atendidos |
| `logistics_http_request_duration_seconds` | histogram | `route`, `method`, `gate` (+ `le`) | Duración del request en segundos |

- `route` es el **patrón** de la ruta (`api/v1/app/orders/{order}`), nunca el path con ids;
  `unmatched` si ninguna ruta coincidió (404 por URL inexistente).
- `status` es el código HTTP exacto (para la clase: `status=~"5.."`).
- `gate`: `app` (`/api/v1/app`), `channels` (`/api/v1/channels`), `integrations`
  (`/api/v1/integrations`), `admin` (`/admin`, `/livewire`), `public` (resto de `/api`), `other`.
- Buckets (`le`): `0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, +Inf`. El histograma no lleva `status` para
  acotar la cardinalidad.
- No se miden `/api/metrics` ni `/up`.

**Spans** — caminos lentos instrumentados con `Span::measure` (sección 5):

| Métrica | Tipo | Etiquetas | Qué mide |
| --- | --- | --- | --- |
| `logistics_span_duration_seconds` | histogram | `span` (+ `le`) | Duración del bloque en segundos |

Valores de `span` hoy: `outbox.pump`, `planning.daily_plan`, `cockpit.aggregate`. Mismos buckets.

**Colas y procesos de fondo:**

| Métrica | Tipo | Etiquetas | Qué mide |
| --- | --- | --- | --- |
| `logistics_queue_depth` | gauge | `queue` | Trabajos listos esperando worker (`LLEN`) |
| `logistics_queue_delayed` | gauge | `queue` | Trabajos diferidos: delay o backoff (`ZCARD :delayed`) |
| `logistics_queue_reserved` | gauge | `queue` | Trabajos tomados y sin confirmar (`ZCARD :reserved`) |
| `logistics_queue_worker_last_activity_age_seconds` | gauge | `queue` | Segundos desde la última vuelta del bucle de un worker de esa cola |
| `logistics_scheduler_last_tick_age_seconds` | gauge | — | Segundos desde el último latido del scheduler |
| `logistics_failed_jobs_total` | gauge | — | Filas en `failed_jobs` |
| `logistics_jobs_queued_total` | gauge | — | Filas en `jobs` (driver `database`) |
| `logistics_telemetry_ingest_buffer_depth` | gauge | `shard` | Lotes de pings GPS en el buffer de ingesta (modo `buffered`); en `sync` vale 0 |
| `logistics_telemetry_ingest_dead_batches` | gauge | — | Lotes de pings apartados tras agotar reintentos (`telemetry:flush-ping-buffer --requeue-dead=N`) |

- `queue`: `default`, `outbox`, `notifications`, `planning`, `sap`.
- **Las dos métricas de edad valen `-1` cuando el latido nunca se observó** (o no se pudo leer
  Valkey). Se usa `-1` y no `0` porque `0` significaría "recién latió". Una alerta de proceso caído
  debe cubrir los dos casos: `x > umbral or x == -1`.
- Scheduler sano: menos de 120 s (late cada minuto). Worker sano: menos de ~30 s (late cada
  `METRICS_WORKER_HEARTBEAT_INTERVAL` segundos, 10 por defecto, también cuando está ocioso).
- El latido del worker se engancha al evento `Illuminate\Queue\Events\Looping`, que el worker dispara
  en cada vuelta de su bucle haya o no trabajos. Se eligió sobre un job centinela o `JobProcessed`
  porque mide que el proceso está **vivo** (no solo que procesó algo), no encola nada y no depende de
  que entre tráfico. Un worker que atiende varias colas (`--queue=outbox,notifications`) marca todas.
  Mientras un job largo corre, el bucle no gira y la edad crece: para la cola `planning` el umbral de
  alerta debe superar la duración normal de un plan.

**Outbox:**

| Métrica | Tipo | Etiquetas | Qué mide |
| --- | --- | --- | --- |
| `logistics_outbox_messages` | gauge | `status` (`pending`, `failed`, `dead_lettered`) | Mensajes por estado |
| `logistics_outbox_backlog_total` | gauge | — | `pending + failed` |
| `logistics_outbox_oldest_pending_age_seconds` | gauge | — | Edad del pendiente más viejo (0 si no hay) |

**PostgreSQL** (solo si el driver es `pgsql`; se consulta el nodo de escritura):

| Métrica | Tipo | Etiquetas | Qué mide |
| --- | --- | --- | --- |
| `logistics_db_connections` | gauge | `state` (`active`, `idle`, `idle in transaction`, …) | Conexiones a la base de la plataforma (`pg_stat_activity`) |
| `logistics_db_max_connections` | gauge | — | `max_connections` del servidor |
| `logistics_db_replication_lag_bytes` | gauge | `client` (IP o nombre del standby) | WAL pendiente de aplicar por standby (`pg_stat_replication`) |

`logistics_db_replication_lag_bytes` sale **sin muestras** cuando no hay standby conectado. Para ver
el lag de todos los standbys el rol de la app necesita `pg_monitor`; sin él PostgreSQL oculta las
columnas y el lag se reporta 0.

**Valkey** (un `INFO`; son estadísticas de toda la instancia, no solo de la DB de caché):

| Métrica | Tipo | Etiquetas | Qué mide |
| --- | --- | --- | --- |
| `logistics_cache_hits_total` | counter | — | `keyspace_hits` |
| `logistics_cache_misses_total` | counter | — | `keyspace_misses` |
| `logistics_cache_memory_bytes` | gauge | — | `used_memory` |

**Testigo:** `logistics_up` (gauge, siempre 1).

#### Cómo se almacenan y cuánto cuestan

- Contadores, histogramas y latidos viven en hashes de Valkey con prefijo `obs:`
  (`obs:http:requests`, `obs:http:duration`, `obs:span:duration`, `obs:heartbeat`), en la conexión
  Redis `default` (DB 0, durable). No usar la conexión `cache`: `cache:clear` los borraría.
- Costo por request: **una** ida y vuelta a Valkey (pipeline de 3 comandos: `HINCRBY`, `HINCRBY`,
  `HINCRBYFLOAT`), ejecutada en `terminate()`, es decir, después de enviar la respuesta con PHP-FPM.
- Costo por scrape: 4 `HGETALL`, 1 pipeline de colas, 1 `INFO` y 7 consultas SQL livianas.
- Los contadores son compartidos por todos los nodos de la app (no hay etiqueta de instancia). Si se
  borran las claves o Valkey reinicia sin persistencia, Prometheus ve un reset de contador, que
  `rate()` e `increase()` toleran.
- Apagar: `METRICS_HTTP_ENABLED=false` (HTTP), `SPANS_ENABLED=false` (spans),
  `METRICS_HEARTBEAT_ENABLED=false` (latidos). Reducir costo sin apagar:
  `METRICS_HTTP_SAMPLE_RATE=0.1` mide 1 de cada 10 requests y cada muestra pesa 10, así las tasas y
  los promedios no se sesgan (los percentiles pierden resolución).
- En `APP_ENV=testing` las métricas HTTP y los spans nacen apagados para que la suite no escriba en
  Valkey.

Consultas de ejemplo:

```promql
# p95 de latencia por puerta
histogram_quantile(0.95, sum by (le, gate) (rate(logistics_http_request_duration_seconds_bucket[5m])))

# tasa de errores 5xx
sum(rate(logistics_http_requests_total{status=~"5.."}[5m])) / sum(rate(logistics_http_requests_total[5m]))

# saturación de conexiones
sum(logistics_db_connections) / logistics_db_max_connections

# scheduler caído
logistics_scheduler_last_tick_age_seconds > 180 or logistics_scheduler_last_tick_age_seconds == -1
```

### 2.2 Métricas objetivo (aún no instrumentadas)

Métricas que el equipo de operación necesita en el dashboard y que todavía no salen por
`/api/metrics`. Aquí se fija **qué** medir y con qué dimensiones; las que ya existen están en 2.1.

### Negocio / operación

| Métrica | Tipo | Dimensiones | Para qué |
| --- | --- | --- | --- |
| Entregas | contador | estado (entregada/fallida), zona, corredor, día | Volumen y tasa de éxito de entrega |
| Escaneos | contador | tipo de evento, nodo, dispositivo | Pulso del flujo de eventos (ADR 008, eventos inmutables) |

### Plataforma / API

| Métrica | Tipo | Dimensiones | Para qué |
| --- | --- | --- | --- |
| Latencia API | histograma | puerta (pública/servicio/admin), ruta, método, código | p50/p95/p99 de respuesta; SLO de la API |
| Profundidad de colas | gauge | nombre de cola | Backlog de trabajo asíncrono; alerta si crece sin bajar |

### Outbox (ADR 002 / ADR 008)

El outbox es el corazón de la integración asíncrona; su salud se vigila aparte:

| Métrica | Tipo | Dimensiones | Alerta |
| --- | --- | --- | --- |
| Outbox pendiente | gauge | destino (SAP/FCM/email/SMS/webhook) | Crece y no drena ⇒ relay caído o destino lento |
| Outbox fallido | contador | destino, tipo de error | Pico ⇒ problema en un destino externo |
| Outbox dead-letter | gauge | destino | **> 0 es incidente**: eventos que agotaron reintentos |
| Edad del pendiente más viejo | gauge | destino | Latencia real de integración (p. ej. retraso de SAP) |

### Ruteo y planificación (ADR 009 / ADR 012)

| Métrica | Tipo | Dimensiones | Para qué |
| --- | --- | --- | --- |
| Duración del auto-planner | histograma | modo (`optimization`/`slots`), nº de pedidos | Vigilar que el job escale a cientos de pedidos sin timeout |
| Resultado del auto-planner | contador | modo, resultado (ok/fallo/reintento) | Idempotencia y fiabilidad del job |
| Llamadas OSRM | contador + histograma | endpoint (`/table`, `/route`), resultado, latencia | Dependencia externa crítica del VRP; detectar lentitud/errores |
| Tamaño de matriz OSRM | histograma | — | Correlacionar latencia del planner con el tamaño del problema |

Nota: las llamadas a OSRM y a los destinos externos **no ocurren dentro de un request de usuario**
(ADR 002), así que su latencia se mide en los jobs, no en la latencia de la API.

## 3. Trazabilidad de la cadena asíncrona

El camino de un cambio no termina en el request; sigue de forma asíncrona:

```
request/evento de dominio
      │  (misma transacción)
      ▼
   outbox  ──────────────▶  relay (job)  ──────────────▶  notificación / SAP / webhook
  event_id                reintentos +                     push FCM, web push,
  (UUID v7)               idempotencia                      email, SMS (ADR 006/010)
```

Para poder seguir **un** evento de punta a punta:

1. Al nacer el evento se fija un id de correlación (el `event_id` UUID v7 del outbox, y/o el
   `request_id` del request que lo originó).
2. Ese id viaja en el payload del evento y se re-emite en el `context` de cada log: al encolar, al
   ejecutar el relay, al despachar la notificación y al confirmar/fallar.
3. En el agregador se filtra por ese id y aparece la traza completa (request → outbox → relay →
   destino), con sus latencias y reintentos.

Cuando se encienda el **tracing de performance de Sentry** (sección 4), cada eslabón se vuelve un span
de la misma transacción, lo que da la traza de forma visual además del filtro por log.

## 4. Cómo enchufar Sentry

Sentry queda **pre-configurado pero no instalado**. `config/sentry.php` ya fija las claves y las envs
(con los nombres que usa `sentry/sentry-laravel`), de modo que encenderlo no requiere decidir nada,
solo instalar y poner el DSN. Mientras el paquete no esté, el archivo es inerte y no cambia nada.

Pasos para habilitarlo:

1. **Instalar el SDK:**

   ```bash
   docker compose exec -T app composer require sentry/sentry-laravel
   ```

2. **Definir el DSN** del proyecto en el entorno (no versionar el valor real):

   ```dotenv
   SENTRY_LARAVEL_DSN=https://<clave>@<org>.ingest.sentry.io/<proyecto>
   SENTRY_RELEASE=         # inyectar el SHA del commit o el tag desde CI
   SENTRY_ENVIRONMENT=production
   ```

   Con el DSN vacío el SDK queda desactivado: seguro para local y staging.

3. **(Opcional) Regenerar la config** con la versión instalada y revisar el diff para no perder los
   valores ya acordados:

   ```bash
   docker compose exec -T app php artisan sentry:publish --dsn=...
   ```

4. **Captura de errores:** al instalar el paquete, el handler de excepciones de Laravel 13
   (`bootstrap/app.php`, `withExceptions`) reporta a Sentry automáticamente. Las excepciones que son
   ruido esperado (validación, 404, auth) se listan en `ignore_exceptions` de `config/sentry.php`.

5. **Tracing de performance (opcional):** fijar `SENTRY_TRACES_SAMPLE_RATE` (empezar bajo, p. ej.
   `0.1`, para no inflar la cuota). Da la latencia de la API por ruta y los spans de colas, SQL,
   Redis y llamadas HTTP de salida (OSRM), alineado con las métricas de la sección 2.

Qué envía Sentry y qué no:

- `send_default_pii=false` por defecto: no manda IP, cookies ni datos de usuario sin decisión
  explícita.
- `sample_rate=1.0`: captura el 100% de los errores (bajar solo por cuota).
- Logs y métricas siguen yendo al agregador (secciones 1–2); Sentry es para **errores y performance**,
  no reemplaza al agregador de logs.

## 5. Spans livianos y cómo enchufar OpenTelemetry

### Span::measure

`App\Observability\Span` cronometra un bloque sin SDK ni dependencia nueva:

```php
$result = Span::measure('outbox.pump', fn () => $pump->handle($limit));
```

- Devuelve lo que devuelve el bloque y deja pasar sus excepciones: medir no cambia la lógica.
- Alimenta `logistics_span_duration_seconds{span}` (una ida y vuelta a Valkey por span).
- Deja una línea de log `span` con `span`, `duration_ms`, `outcome` (`ok`/`error`) y `request_id`,
  solo si el span duró al menos `SPANS_LOG_THRESHOLD_MS` (100 ms por defecto) o falló. Canal y nivel:
  `SPANS_LOG_CHANNEL`, `SPANS_LOG_LEVEL`.
- Si falla el log o Valkey, se ignora.
- El nombre es un literal fijo `contexto.operacion`; nunca un id ni un dato del request.
- Se usa desde Application, Infrastructure, Presentation o `app/`. **Nunca desde Domain.**

Caminos instrumentados hoy:

| Span | Dónde | Qué cubre |
| --- | --- | --- |
| `outbox.pump` | `Logistics\Outbox\Presentation\Console\PumpOutboxCommand` | Una corrida del pump: toma el lote y encola un job por mensaje |
| `planning.daily_plan` | `Logistics\Planning\Infrastructure\Queue\GenerateDailyPlanJob` | La generación completa del plan diario, con su transacción |
| `cockpit.aggregate` | `App\Support\Cockpit\Concerns\ReadsCockpitAggregates::remember` | Cada recálculo real de un agregado del cockpit (no las lecturas servidas del caché) |

### Enchufar OpenTelemetry (opt-in, a futuro)

Hoy no hay SDK de tracing instalado. Cuando haga falta ver trazas distribuidas (waterfall por request,
spans de SQL/Redis/HTTP saliente), el camino es:

1. **Instalar** (decisión explícita; agrega dependencias y, para la auto-instrumentación, una extensión
   de PHP en la imagen):

   ```bash
   docker compose exec -T app composer require \
       open-telemetry/sdk open-telemetry/exporter-otlp open-telemetry/opentelemetry-auto-laravel
   ```

   La auto-instrumentación de Laravel requiere la extensión `opentelemetry` (PECL) en
   `.docker/php`. Sin la extensión, el SDK y el exporter igual funcionan para spans manuales.

2. **Configurar** por las envs estándar del SDK, apuntando a un OpenTelemetry Collector (OTLP):

   ```dotenv
   OTEL_PHP_AUTOLOAD_ENABLED=true
   OTEL_SERVICE_NAME=logistics
   OTEL_TRACES_EXPORTER=otlp
   OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
   OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
   OTEL_TRACES_SAMPLER=parentbased_traceidratio
   OTEL_TRACES_SAMPLER_ARG=0.05
   ```

   Con `OTEL_PHP_AUTOLOAD_ENABLED` ausente o en `false` el SDK queda inerte: es opt-in por entorno.

3. **Conectar `Span`** en su único punto de extensión, `Span::traceWith()`, desde el `boot()` de un
   service provider. El trazador recibe el nombre y el bloque, abre el span real, ejecuta el bloque y
   lo cierra. Los llamadores de `Span::measure` no cambian, y el histograma y el log siguen saliendo:

   ```php
   use App\Observability\Span;
   use OpenTelemetry\API\Globals;
   use OpenTelemetry\API\Trace\StatusCode;

   Span::traceWith(static function (string $name, Closure $callback): mixed {
       $span = Globals::tracerProvider()->getTracer('logistics')->spanBuilder($name)->startSpan();
       $scope = $span->activate();

       try {
           return $callback();
       } catch (Throwable $e) {
           $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);

           throw $e;
       } finally {
           $scope->detach();
           $span->end();
       }
   });
   ```

4. **Correlacionar con los logs:** agregar el `trace_id` activo al Context junto al `request_id`
   (en `AssignRequestId`), para saltar del log a la traza en el agregador.

El Collector también puede scrapear `/api/metrics` (receiver `prometheus`), de modo que métricas y
trazas salgan por el mismo pipeline. Sentry (sección 4) y OpenTelemetry cubren lo mismo en tracing:
elegir uno.

## Referencias

- ADR 002 — I/O externo fuera del request, vía outbox.
- ADR 008 — DDD/CQRS/UUID v7/outbox (eventos inmutables, `event_id`).
- ADR 009 — ruteo OSRM + VRP (llamadas `/table` y `/route`).
- ADR 012 — auto-planner asíncrono en dos modos.
- `config/observability.php` (única puerta de las envs de métricas, latidos, spans y consultas lentas).
- `config/logging.php`, `config/sentry.php`, `.env.example`.
- Código: `app/Metrics/`, `app/Observability/`, `app/Http/Middleware/RecordRequestMetrics.php`,
  `app/Http/Middleware/AssignRequestId.php`.
