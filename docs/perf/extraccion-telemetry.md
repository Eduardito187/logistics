# Servicio de telemetría: ingesta dedicada y unidad de despliegue propia (Ola 6)

> Estado: **ACTIVO**. El contexto telemetry corre como servicio aparte dentro del stack: su propia API
> (`telemetry-api`, rol `telemetry`, pool FPM propio) y su propio worker (`telemetry-worker` en desarrollo,
> programa `worker-telemetry` en staging/producción). nginx le manda las rutas de telemetry y, si la
> unidad no responde, cae al monolito. La ingesta de pings en esa unidad va en modo `buffered`.
>
> Qué sigue compartido **a propósito**: el repositorio (monolito modular, ADR 001/008), la base
> PostgreSQL y Valkey. Separar la base es una migración de datos con lecturas cruzadas que cortar antes
> (sección 6); no se hace hasta que las métricas lo pidan.
>
> Verificado contra los containers (2026-11-13): tests del package en verde incluido el buffer contra
> Valkey real y el camino POST → Valkey → drenado → PostgreSQL; ruteo de nginx a la unidad; filtro de rol
> en vivo (404 fuera de telemetry); respaldo al monolito con la unidad detenida.

Roadmap de origen: `docs/roadmap-alta-disponibilidad-escala.md`, Ola 6.

## 1. Cómo era la ingesta

`POST /api/v1/app/telemetry/pings` (y la gemela de integraciones) hacía TODO dentro del request del
chofer, en una transacción:

1. `ClassifyPingFixes`: una consulta a `telemetry_pings` para informar `accepted` / `duplicate` por ítem.
2. `RecordVehiclePings` por el CommandBus transaccional, que por lote ejecuta del orden de diez
   sentencias: claves ya persistidas, posición en vivo, trip activo, **un INSERT multi-fila** de los pings
   (`ON CONFLICT DO NOTHING` sobre `(vehicle_id, recorded_at, fix_key)`), guardado del trip, upsert de la
   posición en vivo, envíos a bordo (lectura de `execution_deliveries`), geocercas activas, alertas
   abiertas, estado de exceso/desvío, geometría del plan y eventos al outbox.
3. Fan-out en vivo (placa desde `fleet_vehicles`, hot-state en Valkey, broadcast tras el commit).

Lo bueno, que se conserva tal cual: ya era por lote (un POST = hasta 500 lecturas, un INSERT), idempotente
por lectura (`FixKey`), tolerante al desorden y sin un evento por ping.

Lo que faltaba: el trabajo pesado corría **en el worker FPM del request**. Con 2.000 clientes reportando,
esos requests compiten por los mismos hijos FPM y las mismas conexiones de PgBouncer que el resto de la
app, sin tope de concurrencia: la carga de la ingesta la decide el app, no el servidor.

## 2. Diseño de la ingesta dedicada

```
POST pings ──► validar ──► buffer en Valkey (lista por fragmento) ──► 202
                               │   └─ última posición caliente (hot-state) al instante
                               ▼
                   job FlushPingBufferJob (cola `telemetry`)
                               │  candado por fragmento
                               ▼
        PingBufferFlusher: reserva N lotes → agrupa por vehículo → RecordVehiclePings
                               │  (un INSERT multi-fila por vehículo, misma lógica de siempre)
                               ▼
        pings · trip · posición en vivo · geocercas/alertas · outbox · fan-out
```

### 2.1 Modo configurable

`logistics-telemetry.ingest.mode` (`TELEMETRY_INGEST_MODE`):

| Modo | Qué hace el request | Persistencia |
|---|---|---|
| `sync` (default) | Lo mismo que siempre: clasifica y persiste en su transacción. | En el request. |
| `buffered` | Valida, encola el lote en Valkey, adelanta el hot-state y responde 202. **No toca PostgreSQL.** | Worker de la cola `telemetry`, por lotes. |

La decisión vive en `Application/Ingest/IngestVehiclePings` (la Presentation solo habla con Application).
No pasa por el CommandBus a propósito: el decorador transaccional abriría una transacción de base por
cada POST, que es justo lo que el modo `buffered` evita.

### 2.2 Piezas (todas dentro del package telemetry)

| Capa | Pieza | Rol |
|---|---|---|
| Application | `Contract/PingIngestBuffer` | Puerto del buffer: `push`, `reserve`, `acknowledge`, `requeue`, `bury`, `depth`. |
| Application | `Contract/PingFlushScheduler` | Puerto que programa el drenado de un fragmento. |
| Application | `Contract/LivePositionHotState` | Puerto del hot-state de última posición. |
| Application | `Ingest/IngestVehiclePings` | Puerta de entrada: `sync` o `buffered`, backpressure, respaldo a `sync`. |
| Application | `Ingest/PingBufferFlusher` | Una pasada de drenado: reserva, agrupa por vehículo, ingiere, confirma. |
| Infrastructure | `Ingest/RedisPingIngestBuffer` | Buffer en listas de Valkey (cola confiable con `RPOPLPUSH`). |
| Infrastructure | `Ingest/InMemoryPingIngestBuffer` | Misma semántica en memoria (tests). |
| Infrastructure | `Ingest/QueuedPingFlushScheduler` | Encola `FlushPingBufferJob` con coalescing por fragmento. |
| Infrastructure | `Ingest/PingBufferDrainer` | Drena un fragmento bajo candado, con presupuesto de tiempo. |
| Infrastructure | `Queue/FlushPingBufferJob` | Job de la cola `telemetry`. |
| Infrastructure | `Console/FlushPingBufferCommand` | `telemetry:flush-ping-buffer`: drenado de respaldo + operación. |

### 2.3 Buffer

- Una lista de Valkey por **fragmento** (`telemetry:ingest:buffer:{n}`), con `n = crc32(vehicle_id) % shards`.
  Todos los lotes de un vehículo caen en el mismo fragmento.
- Conexión `default` de Redis (la de colas y locks: AOF, sin evicción). Las listas no llevan TTL, así que
  la política `volatile-lru` no las toca.
- Reserva confiable: `RPOPLPUSH buffer → processing`; al terminar la pasada se borra `processing`. Si el
  worker muere a mitad, la siguiente reserva vuelve a entregar lo mismo. Entrega **al-menos-una-vez**;
  la idempotencia por lectura (FixKey + `ON CONFLICT DO NOTHING`) hace que repetir sea inocuo.

### 2.4 Drenado por lotes

- Cada POST en modo `buffered` programa el drenado de su fragmento. Una marca `add()` con TTL hace que,
  de todos los POST de la ventana, solo el primero encole el job, con retardo = `flush_interval_seconds`.
- El job toma un **candado por fragmento** (cache lock con vencimiento): un fragmento lo drena un solo
  proceso a la vez, así las lecturas de un vehículo nunca se procesan en paralelo. Varios fragmentos se
  drenan en paralelo (hasta `numprocs` del worker).
- Cada pasada reserva hasta `flush_batches` lotes y los **consolida por vehículo** (mismo chofer, ruta y
  origen): varios POST chicos del mismo vehículo se vuelven un solo `RecordVehiclePings`, o sea **un
  INSERT multi-fila de pings por vehículo y por pasada** y una sola vuelta de trip, posición en vivo,
  geocercas/alertas y outbox. Lo derivado se dispara exactamente como antes, porque es el mismo handler.
- Si queda carga al agotar el presupuesto (`drain_seconds`), el job se reprograma solo.

**Por qué el INSERT es por vehículo y no uno solo para toda la pasada.** La transacción es por vehículo
a propósito: pings, trip y eventos del outbox de un vehículo se confirman juntos, y un vehículo con datos
problemáticos no arrastra a los demás. Un único INSERT para todos los vehículos obligaría a una
transacción larga por pasada (o a romper esa atomicidad) y ahorraría una sentencia de las ~diez que
cuesta cada vehículo. Si las métricas mostraran que el INSERT es el cuello, el siguiente paso es separar
la persistencia del crudo (pings) del procesamiento derivado, no agrandar la transacción.

### 2.5 Fallos

- Un grupo (vehículo) que falla vuelve al buffer con su contador `attempts`; al llegar a `max_attempts`
  se aparta a `telemetry:ingest:dead` (acotada a `max_dead`). Se recupera con
  `php artisan telemetry:flush-ping-buffer --requeue-dead=N`.
- Si en una pasada hay **varios vehículos y fallan todos**, se asume una falla del sistema (base caída):
  la reserva queda sin confirmar y **sin gastar reintentos**, el drenado se corta y el job se reprograma
  con `failure_backoff_seconds`. Cuando la base vuelve se procesa la misma reserva, en el mismo orden.
  Mientras tanto el buffer se llena y el backpressure le devuelve 429 al app, que conserva sus lecturas.
- Con **un solo vehículo** en la pasada no se puede distinguir base caída de datos inválidos: cuenta
  reintentos. Con los defaults aguanta ~5 pasadas (del orden de un minuto) antes de ir a descartes; solo
  ocurre con tráfico muy bajo, y los descartes se reencolan a mano.
- **Drenado de respaldo**: `telemetry:flush-ping-buffer` corre cada minuto en el scheduler. Si el worker
  de la cola cae o un job se pierde, el buffer igual se vacía. Con el buffer vacío cuesta un par de `LLEN`.
- **Buffer caído** (Valkey no responde): el request cae al camino `sync` (`fallback_to_sync`, default
  `true`) y persiste en su transacción. No se pierde el lote.

### 2.6 Backpressure

Tope total `max_batches` (default 20.000 lotes, repartido entre fragmentos). Con el fragmento lleno el
POST **no toma el lote** y responde:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 15
{"type": ".../ingest_backpressure", "status": 429, "code": "ingest_backpressure",
 "detail": "The telemetry ingest buffer is full; retry this batch later.",
 "error": {"code": "ingest_backpressure", "message": "...", "details": {"retry_after_seconds": 15}}}
```

Se eligió **rechazar con 429** y no descartar lecturas viejas: el app ya guarda sus lecturas y reintenta,
y el crudo de pings alimenta las migas de pan por envío, donde un hueco silencioso es peor que un
reintento. El tope se chequea con `LLEN` antes del `LPUSH` (no atómico): bajo concurrencia puede pasarse
por unos pocos lotes, inocuo para un límite de protección.

Profundidad del buffer: `php artisan telemetry:flush-ping-buffer --status` (por fragmento + descartes) y
la métrica `logistics_telemetry_ingest_buffer_depth{shard}` en `/api/metrics`.

### 2.7 Posición en vivo

- En modo `buffered`, el request escribe la **última posición caliente** en Valkey en el momento
  (`LiveVehicleState::touch`): toma la lectura más reciente del lote, conserva la placa ya conocida y no
  retrocede si llega un lote atrasado. Son dos operaciones de Valkey, sin base.
- La tabla `telemetry_vehicle_positions` (lo que lee `GET /app/telemetry/fleet/live` y el mapa del admin)
  y el broadcast por WebSockets se actualizan en el drenado: atraso normal = `flush_interval_seconds`
  (2 s) más la espera del worker. El throttle del broadcast ya era de 3 s.
- `received_at` de los pings pasa a ser el instante del drenado, no el del POST.

### 2.8 Qué cambia en el contrato (solo en modo `buffered`)

- Toda lectura bien formada se informa `accepted`; `duplicate_count` es 0. Los duplicados se descartan
  igual al persistir, pero el request ya no consulta la base para clasificarlos.
- El cuerpo trae `buffered: true`.
- Puede responder `429` + `Retry-After`.

En modo `sync` la respuesta es idéntica a la de siempre.

## 3. Dónde corre cada modo

| Proceso | Modo de ingesta | Por qué |
|---|---|---|
| `telemetry-api` (unidad propia) | `buffered` (lo fija el compose) | Camino normal: responde 202 sin tocar PostgreSQL. |
| `app` (monolito) | `sync` (default de la config) | Camino de respaldo: solo recibe pings si la unidad no responde. |

No hay nada que activar a mano. Para volver al comportamiento anterior en la unidad:
`TELEMETRY_INGEST_MODE=sync` en el entorno del compose. Lo que haya quedado en el buffer lo siguen
drenando el worker y el respaldo del scheduler.

Operación:

- `php artisan telemetry:flush-ping-buffer --status`: profundidad por fragmento y descartes.
- `/api/metrics`: `logistics_telemetry_ingest_buffer_depth{shard}`, `logistics_telemetry_ingest_dead_batches`
  y la cola `telemetry` en `logistics_queue_depth`.
- `TELEMETRY_INGEST_BUFFER_SHARDS` y la cantidad de procesos del worker definen el paralelismo del
  drenado. Antes de **bajar** la cantidad de fragmentos hay que vaciar el buffer.

## 4. `telemetry-api` como unidad de despliegue aparte

### 4.1 Rol del proceso

`config/service.php` → `APP_SERVICE_ROLE` (default `monolith`). Lo aplica `App\Support\ServiceRole`:

- `monolith`: no registra nada; el proceso se comporta exactamente como hoy.
- `telemetry`: el proceso antepone un middleware global que responde **404** a toda ruta fuera de
  `api/v1/app/telemetry/*`, `api/v1/integrations/telemetry/*`, `/up`, `/api/v1/health` y `/api/metrics`.
- Un rol desconocido se trata como `monolith`.

Los providers y bindings se registran igual en todos los roles (otros contextos pueden necesitar sus
contratos de lectura). Se filtra por ruta de la petición y no podando el router para que sea O(1) por
request y compatible con `route:cache` (la caché de rutas es la misma para todos los roles).

`ServiceRole::serves('<contexto>')` queda disponible para que cada contexto decida si monta sus rutas.
Hoy las rutas de todos los contextos las integra `routes/api.php`; filtrar ahí por `serves()` es una
optimización posible, con una condición: la caché de rutas dejaría de ser compartible entre roles.

Para extraer otro contexto: agregar su rol en `config/service.php` (`contexts` + `paths`).

### 4.2 El servicio en cada entorno

| Entorno | API | Worker | Archivo |
|---|---|---|---|
| Desarrollo | `telemetry-api` | `telemetry-worker` (cola `telemetry`) | `docker-compose.yml` |
| Staging | `telemetry-api` | programa `worker-telemetry` del contenedor `worker` | `.docker/compose/docker-compose.staging.yml` |

Levanta con el `docker compose up` de siempre; todas sus variables tienen default. Escalar la API:

```bash
docker compose up -d --scale telemetry-api=3
```

En desarrollo `bootstrap/cache` se comparte con `app` por el bind mount y la config cacheada lleva el
rol adentro: por eso la unidad fija `APP_CONFIG_CACHE=/tmp/config-telemetry.php`. En staging cada
contenedor tiene su propio filesystem y no hace falta.

La unidad no migra, no instala composer, no corre el scheduler y no reinicia los workers del resto: el
entrypoint salta `queue:restart` y `schedule:clear-cache` cuando `APP_SERVICE_ROLE` no es `monolith`.

### 4.3 Ruteo y respaldo

`.docker/nginx/default.conf` (el mismo archivo se hornea en la imagen `web` de staging):

- `/api/v1/app/telemetry/*` y `/api/v1/integrations/telemetry/*` → `telemetry-api:9000`.
- Upstream por variable + `resolver` de Docker: re-resuelve si el contenedor se recrea y reparte entre
  las réplicas de `--scale`.
- **Respaldo:** si la unidad no existe, está caída o reiniciando (502/503/504, conexión rechazada o
  nombre sin resolver), el request se reenvía al monolito con su cuerpo intacto. Un deploy o una caída del
  servicio no pierde lotes de pings.

Para sacar la unidad del camino basta detenerla (`docker compose stop telemetry-api`): el respaldo
atiende solo.

## 5. Mapa de acoples de telemetry

### 5.1 Lo que telemetry usa de otros

| Dependencia | Dónde | Tipo | Al extraer |
|---|---|---|---|
| `Logistics\Shared\*` (buses CQRS, Clock, ids, agregados, eventos, poda) | todo el package | núcleo compartido | Viaja como librería. |
| `Logistics\Outbox` (`RecordDomainEvents`, `OutboxMessage`, `MessageDispatcher`) | handlers + reactor | outbox | Viaja como librería; el transporte pasa a red. |
| Tabla `execution_deliveries`, `execution_scan_events` | `DbShipmentCustodyReader` (contrato `ShipmentCustodyReader`) | lectura SQL directa, **en el camino de ingesta** | Proyección local alimentada por eventos de execution. |
| Tabla `planning_plans`, `execution_driver_routes`, `execution_driver_route_stops` | `DbActiveRouteReader` (contrato `ActiveRouteReader`) | lectura SQL directa, en la ingesta (desvío) y en ETAs | Proyección local (plan publicado, ruta iniciada). |
| Tabla `fleet_vehicles`, `fleet_drivers`, `execution_driver_routes` | `DbFleetDirectory` (contrato `FleetDirectory`), `FleetPlateResolver` | lectura SQL directa (placas, choferes) | Proyección local o API de fleet con caché. |
| `Logistics\Network\...\NodeRecord`, `Logistics\Fleet\...\VehicleModel` | Filament (`GeofenceResource`, `NodeNames`, `VehiclePlates`) | modelo Eloquent ajeno, solo admin | El admin se queda en el monolito y consume la API de telemetry. |
| Tablas `planning_plans`, `execution_driver_routes*` | `Presentation/Filament/Pages/LiveFleetMap` | SQL directo desde Presentation, solo admin | Ídem. |
| `App\Models\User`, `App\Http\Support\ApiPagination`, `App\Filament\Support\RequestScopedQuery`, `App\Support\ServiceRole` | 3 controllers, `TripResource`, provider | clases de la app anfitriona | Mover a `shared` o duplicar el mínimo. |
| Autenticación de las puertas (Sanctum `auth:sanctum` + `device`, `service.auth`) | middleware de `routes/api.php` | tablas de usuarios/tokens/dispositivos en la misma base | Validación de token por red (introspección, JWT o gateway). |
| `routes/api/telemetry.php`, `openapi/v1.yaml` | fuera del package | integración del monolito | Mover las rutas al package. |

Los tres lectores cross-context ya están detrás de **contratos de Application** con adaptadores en
`Infrastructure/CrossContext` y degradan con gracia (`SafeRead`): cambiar el adaptador SQL por uno de
proyección local o HTTP no toca Application ni Domain.

### 5.2 Lo que otros usan de telemetry

| Consumidor | Qué usa | Tipo | Al extraer |
|---|---|---|---|
| tracking (`TruckPositionOnVehicleReport`) | evento `telemetry.vehicle_position_reported` | **outbox** | Ya es asíncrono; pasa a red. |
| outbox (routing) → `BindTripToRouteOnRouteStarted` | evento `execution.driver_route_started` | **outbox** (reactor de telemetry) | Suscripción por red. |
| `app/` (ControlTower, `VehicleHub`, `FlaggedRoute`, `NotificationsController`, widget de alertas) | queries/commands de Application (`GetLiveFleetPositions`, `ListAlerts`, `AcknowledgeAlert`) por el bus | llamada **en proceso** | Cliente HTTP a la API de telemetry. |
| `app/` (mismos + `AdminPanelProvider`) | `Presentation/Filament/*` (`LiveFleetMap`, `VehiclePlates`, `TelemetryAlertResource`), VOs `AlertType`/`AlertSeverity` | clases de Presentation/Domain en proceso | El admin de telemetry se queda en el monolito sobre la API. |
| fleet (`ViewVehicle`) | `TripResource` (enlace entre recursos Filament) | Presentation → Presentation | Enlace por URL. |
| fleetops (`TelemetryDistanceReader`, `GetVehicleCostSummaryHandler`) | tabla `telemetry_trips` | lectura SQL directa | Proyección de km por evento `TripCompleted` o API. |
| shipments (`DbShipmentSummaryReader`) | tabla `telemetry_alerts` | lectura SQL directa | Proyección por evento de alerta o API. |
| `app/Support/Cockpit/*`, widget de alertas, vista materializada `cockpit_daily_summary` | tablas `telemetry_alerts`, `telemetry_trips`, `telemetry_pings` | lectura SQL directa | Agregados publicados por telemetry. |
| Operación (`ManagePartitionsCommand`, `MaintainDatabaseCommand`, `DbRowPruner`, `RestoreDrillCommand`, `LoadTestCleanupCommand`) | tablas `telemetry_*` | mantenimiento de base | Se mudan con la base de telemetry. |
| Seeders (`TodayScenarioSeeder`, `MultiRouteTodaySeeder`, `DatabaseSeeder`) | `RecordVehiclePings`, `PingRecord`, `TelemetryDemoSeeder` | datos de prueba | Sembrar por la API. |

No se invirtió ninguna dependencia en este cambio: las lecturas cross-context del camino caliente ya van
por contrato, y las directas que quedan (Filament, cockpit, fleetops, shipments) no son triviales de mover
sin correr la suite.

## 6. Pasos que faltan para la extracción física

En orden; cada uno es desplegable por separado.

1. **Hecho:** ingesta `buffered` y unidad `telemetry-api` + worker propios detrás de nginx. Con eso la
   ingesta ya no compite con el request del usuario. Los pasos siguientes solo si las métricas lo piden.
2. **Cortar las lecturas SQL entrantes** a `telemetry_*` (fleetops, shipments, cockpit): que telemetry
   publique lo que necesitan (km por trip cerrado, alertas) por outbox y cada consumidor mantenga su
   proyección, o que consulten la API.
3. **Cortar las lecturas SQL salientes**: reemplazar `DbShipmentCustodyReader`, `DbActiveRouteReader` y
   `DbFleetDirectory` por proyecciones locales (`telemetry_onboard_shipments`, `telemetry_planned_routes`,
   `telemetry_fleet_directory`) alimentadas por eventos de execution, planning y fleet. Los contratos de
   Application no cambian.
4. **Autenticación sin base compartida**: validar el token del app y las credenciales de integración sin
   leer las tablas del monolito (introspección con caché, token firmado o gateway que inyecte la identidad).
5. **Outbox sobre red**: hoy el relay despacha en proceso por `eventName`. Hace falta un transporte
   (stream de Valkey, cola o HTTP firmado) con el mismo contrato: idempotente por `event_id`, reintentos y
   dead-letter. `telemetry.vehicle_position_reported` sale y `execution.driver_route_started` entra.
6. **Base propia**: mover `telemetry_*` a su propia instancia (o a un almacén time-series para
   `telemetry_pings`). Las migraciones ya viven en el package; se llevan el particionado, la poda y el
   backup de esas tablas.
7. **Admin**: dejar las pantallas Filament de telemetry en el monolito consumiendo la API, o darles un
   panel propio. Sacar los modelos Eloquent ajenos y el SQL directo de `LiveFleetMap`.
8. **Repo propio**: mover `packages/logistics/telemetry` + `routes/api/telemetry.php` + su parte de
   `openapi/v1.yaml`, con `logistics/shared` y `logistics/outbox` como dependencias de Composer. Sacar las
   cuatro clases `App\*` (sección 5.1).
9. **Buffer y colas propias**: instancia de Valkey dedicada para el buffer de ingesta y la cola
   `telemetry` (`TELEMETRY_INGEST_BUFFER_CONNECTION`).

## 7. Verificación hecha y lo que falta medir

Hecho contra los containers (2026-11-13):

- Tests del package telemetry en verde, incluidos `RedisPingIngestBufferTest` (buffer contra Valkey real:
  orden, reserva tras una pasada interrumpida, backpressure, descartes y el camino completo POST → Valkey →
  drenado → PostgreSQL) y `BufferedPingIngestTest`.
- `nginx -t` y un POST real por nginx atendido por `telemetry-api`.
- Filtro de rol dentro de la unidad: 404 para rutas ajenas, las de telemetry y `/up` responden.
- Respaldo: con la unidad detenida el mismo POST lo atiende el monolito.

Falta (necesita carga real): medir con `loadtest/` el p95 de `POST /app/telemetry/pings` y la profundidad
del buffer a 2.000 usuarios, y ajustar `TELEMETRY_API_FPM_MAX_CHILDREN`, fragmentos y procesos del worker.

## 8. Variables

Todas en `packages/logistics/telemetry/config/logistics-telemetry.php` (única puerta de env del
contexto), salvo el rol, que vive en `config/service.php`.

| Variable | Default | Qué controla |
|---|---|---|
| `APP_SERVICE_ROLE` | `monolith` | Rol del proceso (`monolith` / `telemetry`). |
| `TELEMETRY_INGEST_MODE` | `sync` en el monolito, `buffered` en `telemetry-api` | Camino de ingesta (`sync` / `buffered`). |
| `TELEMETRY_INGEST_QUEUE` | `telemetry` | Cola del job de drenado. |
| `TELEMETRY_INGEST_BUFFER_CONNECTION` | `default` | Conexión Redis/Valkey del buffer. |
| `TELEMETRY_INGEST_BUFFER_KEY_PREFIX` | `telemetry:ingest` | Prefijo de las claves del buffer. |
| `TELEMETRY_API_FPM_MAX_CHILDREN` (y `_START_SERVERS`, `_MIN_SPARE`, `_MAX_SPARE`) | `20` / `2` / `2` / `6` | Pool FPM propio de `telemetry-api` (solo compose). |
| `TELEMETRY_INGEST_BUFFER_SHARDS` | `4` | Fragmentos del buffer (paralelismo del drenado). |
| `TELEMETRY_INGEST_BUFFER_MAX_BATCHES` | `20000` | Tope total de lotes en espera (backpressure). |
| `TELEMETRY_INGEST_RETRY_AFTER_SECONDS` | `15` | `Retry-After` del 429. |
| `TELEMETRY_INGEST_FALLBACK_TO_SYNC` | `true` | Con el buffer caído, persistir en el request. |
| `TELEMETRY_INGEST_FLUSH_INTERVAL_SECONDS` | `2` | Ventana de flush (atraso normal de la persistencia). |
| `TELEMETRY_INGEST_FLUSH_BATCHES` | `200` | Lotes del buffer por pasada. |
| `TELEMETRY_INGEST_DRAIN_SECONDS` | `20` | Presupuesto de tiempo de un drenado. |
| `TELEMETRY_INGEST_LOCK_SECONDS` | `60` | Vida del candado por fragmento. |
| `TELEMETRY_INGEST_MAX_ATTEMPTS` | `5` | Reintentos de un lote antes de descartes. |
| `TELEMETRY_INGEST_FAILURE_BACKOFF_SECONDS` | `15` | Espera tras una pasada fallida entera. |
| `TELEMETRY_INGEST_MAX_DEAD` | `10000` | Tope de la lista de descartes. |
