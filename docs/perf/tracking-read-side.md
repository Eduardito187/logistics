# Read-side del seguimiento público — caché de lectura y preparación para extraerlo

> Ola 6 de `docs/roadmap-alta-disponibilidad-escala.md`: segundo candidato a extraer + "edge/caché de
> lectura del tracking público con TTL corto".
>
> **Estado:** implementado en código, **sin correr gates ni carga** (regla "no docker salvo pedido"). Los
> números de la sección 5 son **estimaciones de diseño**, no mediciones: hay que validarlas con el load
> test antes de darlas por buenas.

## 1. Problema y meta

El enlace público de seguimiento (`/t/{token}` y `GET /api/v1/channels/tracking/{token}`) es la
superficie con más lecturas de la plataforma: lo abre el cliente final y lo vuelve a consultar muchas
veces, mientras que el dato cambia poco (un puñado de cambios de estado por pedido).

Meta: **2.000 clientes concurrentes, p95 < 200 ms, mínima CPU y sin tocar la base primaria en el caso
común.**

Antes de este cambio, cada consulta hacía como mínimo un `SELECT` por token (dos en el camino 404/410),
la página HTML abría una sesión por visita y el ETag se calculaba hasheando el cuerpo en cada request.

## 2. Capas de caché

De afuera hacia adentro. Cada capa absorbe lo que la anterior deja pasar.

| # | Capa | Vida | Se invalida por evento | Estado |
|---|------|------|------------------------|--------|
| 0 | Navegador / CDN (`Cache-Control: public, max-age=20, stale-while-revalidate=30` + `ETag`) | 20 s (+30 s revalidando) | No (solo CDN con purga) | Activa |
| 1 | Micro-caché de nginx (`fastcgi_cache`, clave por URI) | 5 s | No | **Opcional**, apagada |
| 2 | Valkey: foto por token (`Cache::flexible`) | fresca 20 s, stale hasta 120 s | **Sí** | Activa |
| 3 | Réplica de lectura de PostgreSQL (`DB_READ_HOST`) | — | — | Si está configurada |
| 4 | Primario | — | — | Solo si no hay réplica o el request ya escribió (sticky) |

### 2.1 Capa 2 — foto por token en Valkey

- **Qué se guarda:** la forma **primitiva** (array) de `PublicTrackingSnapshot`:
  `{state: active|expired|not_found, view: {...}|null, etag: string|null}`. Nunca un objeto serializado
  (regla 7). `view` es exactamente `PublicTrackingView::toArray()`: sin id de seguimiento, sin id de
  envío, sin token, sin nombre ni dirección. **No hay datos personales en el caché.**
- **Clave:** `tracking:public:v1:{sha256(token)}` (más el prefijo de caché de la app). Se guarda el hash
  y no el token porque el token es la credencial del enlace y no debe quedar legible en el keyspace.
  Subir `v1` si cambia la forma primitiva.
- **Anti-stampede:** `Cache::flexible($key, [ttl_fresh, ttl_stale], ...)`. Dentro de `ttl_fresh` no se
  toca la base. Entre `ttl_fresh` y `ttl_stale` se sirve el valor guardado y **un solo** request lo
  refresca en segundo plano (después de enviar la respuesta), con lock.
- **Caché negativo:** un token con forma válida pero sin seguimiento se guarda como `not_found` con un
  TTL duro y corto (`ttl_negative`, 15 s, nunca mayor que `ttl_fresh`). Un token con forma inválida ni
  consulta la base ni ocupa clave.
- **Una foto, tres consultas:** `GetPublicTrackingSnapshot` (nueva), `GetPublicTracking` y
  `GetPublicLinkState` leen la misma foto. El camino 404/410 dejó de hacer un segundo `SELECT`.
- **ETag:** se calcula una vez al armar la foto (SHA-256 de la vista) y viaja dentro de ella. El
  controller JSON lo usa tal cual: no vuelve a hashear el cuerpo.
- **Tolerancia a fallos:** si Valkey no responde o la entrada está corrupta, se lee en vivo de la base
  (se reporta la excepción). El seguimiento no se cae por el caché.

Piezas (todas en `packages/logistics/tracking/src`):

| Capa | Archivo | Rol |
|------|---------|-----|
| Application | `Contract/PublicTrackingReader.php` | Puerto de lectura del enlace público (la costura de extracción) |
| Application | `Service/LivePublicTrackingReader.php` | Lee del repositorio: un `SELECT` por token |
| Application | `ReadModel/PublicTrackingSnapshot.php` | La foto y su forma primitiva |
| Application | `Query/GetPublicTrackingSnapshot/*` | Consulta única para Presentation |
| Infrastructure | `ReadSide/PublicTrackingCache.php` | Dueño de claves y TTL |
| Infrastructure | `ReadSide/CachedPublicTrackingReader.php` | Decorador cacheado del lector |
| Infrastructure | `Persistence/CacheInvalidatingTrackingRepository.php` | Decorador del repositorio: invalida |

### 2.2 Capa 0 — cabeceras para el borde

`Presentation/Http/PublicCacheHeaders.php` aplica, **solo en 200 y solo a visitantes anónimos**:

```
Cache-Control: max-age=20, public, stale-while-revalidate=30
ETag: "<sha256>"
```

- JSON (`GET /api/v1/channels/tracking/{token}`): ETag = huella de la foto cacheada.
- HTML (`GET /t/{token}`): ETag = SHA-256 del HTML final (incluye productos y estado de la encuesta).
- Con `If-None-Match` coincidente: **304 sin cuerpo**.
- No se marca `public` si el request trae usuario autenticado o `Authorization`, o si la respuesta
  fija cookies. Los 404/410 conservan el `no-cache, private` por defecto.
- La ruta autenticada `GET /api/v1/app/tracking/shipments/{id}` no usa el trait: nunca sale `public`.

### 2.3 Capa 1 — micro-caché de nginx (opcional)

Snippet en `.docker/nginx/snippets/tracking-microcache.conf.example`. No se toca
`.docker/nginx/default.conf`. Para activarlo:

1. Copiar la **PARTE A** del snippet (descomentada) a un archivo propio y montarlo en
   `/etc/nginx/conf.d/00-tracking-microcache-http.conf` (contexto `http`: define `fastcgi_cache_path` y
   los `map` de bypass).
2. Copiar el snippet sin `.example` y agregar dentro del `server {}` del entorno, antes del
   `location ~ \.php$`: `include /etc/nginx/snippets/tracking-microcache.conf;`
3. `nginx -t && nginx -s reload`.
4. Verificar: `curl -sI https://<host>/t/<token> | grep -i x-tracking-cache` → `MISS`, luego `HIT`.

Qué hace: guarda 5 s los 200/404/410 de `GET /t/{token}`, con clave `método|host|$uri` (sin query
string), `fastcgi_cache_lock` (anti-stampede), `use_stale` (sirve lo último si FPM falla) y bypass si el
request trae cookie de sesión o `Authorization`. El bloque del JSON de canal viene **comentado**: un HIT
no vuelve a verificar el secreto del canal ni cuenta para su rate limit durante esos 5 s; leer la
advertencia del snippet antes de activarlo.

Esta capa **no se invalida por evento**: suma hasta 5 s de antigüedad. Por eso no se sube el TTL.

## 3. Invalidación

**Un único lugar:** `CacheInvalidatingTrackingRepository::save()`, el decorador que envuelve al
repositorio Eloquent (binding de `TrackingRepository` en `TrackingServiceProvider`). Los siete puntos
que graban un seguimiento pasan por `save()`:

| Origen | Archivo |
|--------|---------|
| Abrir seguimiento (`OrderPlaced`) | `OpenTrackingHandler.php:54` |
| Ligar envío | `BindShipmentToTrackingHandler.php:69` |
| Avanzar estado (pago, etiquetas, cancelar, reprogramar, devolver) | `AdvanceTrackingHandler.php:39` |
| Proyección de execution (cargado, salió, entregado, fallido) | `ProjectExecutionEventHandler.php:82`, `:133` |
| Cascada de paradas restantes a los envíos de atrás | `ProjectExecutionEventHandler.php:197` |
| Ubicar en ruta | `PlaceShipmentOnRouteHandler.php:45` |
| Posición del camión | `UpdateTruckPositionHandler.php:58` |

Los reactores del outbox (`Infrastructure/Outbox/*`) despachan esos comandos, así que quedan cubiertos
sin tocar ninguno.

**Qué invalida.** Se compara la foto guardada contra el agregado recién grabado y se olvida la clave
solo si cambió lo que el cliente debe ver de inmediato:

- `status`, `remaining_stops` o `route_position` distintos, o
- la entrada era negativa (`not_found`), vencida o ilegible.

La **posición del camión y la ETA no invalidan**: llegan cada pocos segundos por envío y las cubre
`ttl_fresh`. Invalidar en cada ping de GPS anularía el caché justo cuando más se consulta (pedido en
camino). Costo de la comparación: un `GET` a Valkey por `save()`.

**Cuándo.** En el acto y, si hay una transacción abierta, otra vez **después del COMMIT**
(`DB::afterCommit`). Sin la segunda pasada, un lector concurrente podría repoblar el caché con el
estado previo al commit y dejarlo fresco toda la ventana. Al olvidar se borra también la marca de
creación de `flexible`, para que un refresco en segundo plano ya en vuelo no reescriba la foto vieja.

**Qué NO invalida** (lo acota el TTL): escrituras que no pasan por el repositorio — seeders que usan
`TrackingRecord` directo, `DELETE` de los comandos de demo/load test, SQL manual y los backfills
(`delivered_at`, `customer_email`; ninguno cambia la vista pública, `delivered_at` solo mueve el
vencimiento del enlace).

## 4. Lecturas a réplica y escrituras en el GET

`config/database.php` rutea los `SELECT` al pool `read` cuando `DB_READ_HOST` está definido, con
`sticky => true`: si el request ya escribió en esa conexión, sus lecturas siguientes van al primario.

**El handler público no escribe ni abre transacción.** `LivePublicTrackingReader` hace un
`findByToken` (un `SELECT` sobre `tracking_shipments`, conexión por defecto). El `QueryBus` no envuelve
en transacción. No hay contador de visitas ni marca de "link visto".

Escrituras encontradas en el camino del GET público:

| # | Dónde | Qué escribe | Estado |
|---|-------|-------------|--------|
| 1 | Ruta `/t/{token}` bajo la pila `web` | Abría una **sesión por visita** (`SESSION_DRIVER=redis`: un `SET` en Valkey) y devolvía `Set-Cookie` de sesión y XSRF. Además impedía cualquier caché compartido. | **Corregido**: la ruta sale de `web`. La página no usa sesión, CSRF ni cookies. |
| 2 | `App\Http\Middleware\AuthenticateChannel` (`channel.auth`, puerta `channels`) | `ApiChannel::markUsed()` hace un **`UPDATE api_channels SET last_used_at`** en cada request. Como es una escritura en la conexión por defecto, **activa el sticky y manda al primario el resto de las lecturas de ese request**. | **Pendiente, fuera del territorio de este cambio** (`app/`). |
| 3 | Mismo middleware | `Hash::check` (bcrypt) del secreto del canal en cada request: decenas de ms de CPU por request, más que el resto del camino completo. Y un `SELECT api_channels` previo. | **Pendiente**, mismo motivo. |
| 4 | `throttle:channels` | Contador de rate limit en Valkey. | Esperado; es barato. |

Los puntos 2 y 3 son hoy **el costo dominante del JSON público** y lo que impide cumplir "sin tocar el
primario" en esa ruta, aunque la foto salga de Valkey. Propuesta (para quien sea dueño de `app/`):

- cachear la resolución del canal en Valkey 60 s, guardando una forma primitiva y un HMAC del secreto
  ya verificado (no el secreto), para no repetir bcrypt en cada request;
- mover `last_used_at` fuera del request: como mucho una escritura por canal por minuto
  (`Cache::add` como compuerta) o diferida con `defer()`.

La página HTML `/t/{token}` no pasa por ese middleware: con este cambio su camino común es Valkey (la
foto) más las consultas de productos y encuesta (ver 6.3).

## 5. Números esperados (estimación, sin medir)

Supuestos: 2.000 clientes mirando su propio enlace, un poll cada 20-30 s, Valkey en la misma red.

| Escenario | Requests a PHP | Consultas a la base por request |
|-----------|----------------|---------------------------------|
| Antes | ~100 rps | 1-2 `SELECT` (+ sesión en la página HTML) |
| Con capa 2 (Valkey) | ~100 rps | 0 en HIT; un `SELECT` por token cada 20 s en total |
| Con capa 0 respetada por el cliente | menos: el navegador no vuelve a pedir dentro de `max-age` y revalida con 304 | igual que arriba |
| Con capa 1 (nginx) y enlaces compartidos | como mucho 1 request por token cada 5 s | igual que arriba |

- Con 2.000 tokens distintos el micro-caché de nginx casi no suma (cada token lo pide un solo cliente):
  rinde cuando **muchos clientes miran el mismo enlace** o ante un pico/abuso sobre pocos tokens. La
  capa que resuelve el caso "cada cliente su enlace" es Valkey.
- Carga a la base por el seguimiento público con las capas activas: del orden de **un `SELECT` por token
  activo cada 20 s**, a la réplica. Con 2.000 enlaces activos, ~100 `SELECT`/s por índice único, en el
  peor caso; baja con cada 304 del navegador.
- Latencia de un HIT de Valkey: un `MGET` (valor + marca de creación). El p95 de la respuesta queda
  dominado por el arranque del framework en FPM, no por datos. Para bajar ese piso: Octane (ola 2) o el
  micro-caché.
- Memoria en Valkey: unos cientos de bytes por token activo, con vida máxima `ttl_stale` (120 s).

Cómo validarlo: load test (k6) con el escenario de tracking, mirando `X-Tracking-Cache`, el conteo de
`SELECT ... tracking_shipments` en la réplica y `keyspace_hits/misses` de Valkey.

## 6. Preparación para extraer el read-side

### 6.1 Mapa de acoples — tracking hacia otros contextos

**Por outbox / contrato (bien encaminado):**

| Dependencia | Dónde | Forma |
|-------------|-------|-------|
| `Logistics\Outbox` (`MessageDispatcher`, `OutboxMessage`) | 9 reactores en `Infrastructure/Outbox/*` | Outbox: consumen eventos por **nombre** (string), sin importar clases de execution/shipments/labels |
| `Logistics\Outbox\Application\RecordDomainEvents` | handlers de comando (`OpenTracking`, `BindShipmentToTracking`, `AdvanceTracking`, `PlaceShipmentOnRoute`, `ProjectExecutionEvent`, `ProjectUnitExecutionEvent`, `RecordSatisfactionSurvey`) | Outbox: publican eventos propios |
| `Logistics\Settings\...\GetSettingQuery` | `Infrastructure/Settings/SettingsPublicLinkTtl.php:7` | Llamada **en proceso** por `QueryBus`, detrás del contrato `PublicLinkTtl`, tolerante (default 72 h) |
| `Logistics\Shared` | el package entero | Núcleo compartido (bus, `Clock`, ids) |

**Acople a datos — lecturas directas a tablas/modelos de otros contextos:**

| Archivo:línea | Tabla / modelo ajeno | ¿En el camino público? |
|---------------|----------------------|------------------------|
| `Application/Query/GetPublicUnits/GetPublicUnitsHandler.php:9`, `:89` | `shipment_units` vía `PhysicalUnitRecord` (shipments) | **Sí** — página HTML y `.../units` |
| `Application/Query/GetShipmentTracking/GetShipmentTrackingHandler.php:10`, `:39` | `shipments` vía `ShipmentRecord` | No (puerta app) |
| `Application/Query/GetShipmentUnits/GetShipmentUnitsHandler.php:77` | `execution_node_reception_unit_locations` | No |
| `.../GetShipmentUnitsHandler.php:105` | `shipments` | No |
| `.../GetShipmentUnitsHandler.php:116` | `shipment_lines` | No |
| `.../GetShipmentUnitsHandler.php:123` | `labels_emitted` (+ `Logistics\Labels\Domain\ValueObject\SignedLabel`, `:8`) | No |
| `.../GetShipmentUnitsHandler.php:138`, `:166` | `shipment_units` (+ `PhysicalUnitRecord`, `:11`) | No |
| `Infrastructure/ReadSide/DbCustomerContactReader.php:31` | `shipments` (email/teléfono) | No (proyección de entrega) |
| `Infrastructure/ReadSide/DbShipmentOrderReferenceReader.php:35` | `shipments` | No (upsert de la proyección) |
| `Infrastructure/Persistence/Backfill/SqlCustomerEmailBackfill.php:75` | `shipments` (join) | No (comando) |
| `Infrastructure/Persistence/Backfill/SqlDeliveredAtBackfill.php:46`, `:60`, `:73` | `execution_deliveries`, `outbox_messages` | No (comando) |
| `Presentation/Console/ReconcileTrackingFromExecutionCommand.php:51` | `execution_deliveries` | No (comando) |
| `Infrastructure/Persistence/Eloquent/TrackingRecord.php:11`, `:94` | `belongsTo(ShipmentRecord)` | No (admin) |
| `Infrastructure/Persistence/Eloquent/UnitTrackingRecord.php:11`, `:60` | `belongsTo(PhysicalUnitRecord)` | No (admin) |
| `Infrastructure/Persistence/Eloquent/SatisfactionSurveyRecord.php:10`, `:59` | `belongsTo(ShipmentRecord)` | No (admin) |
| `Presentation/Filament/Resources/ShipmentTrackingResource.php:32`, `:370`, `:384`, `:400` | `ShipmentRecord` | No (admin) |
| `Presentation/Filament/Resources/SatisfactionSurveyResource.php:20` | `ShipmentRecord` | No (admin) |
| `Presentation/Filament/.../ListShipmentTrackings.php:7`, `ShipmentTrackingResource.php:7` | `App\Filament\...` (helpers del admin) | No (admin) |

Nota de capas: dos handlers de **Application** importan modelos Eloquent de shipments
(`GetPublicUnitsHandler`, `GetShipmentTrackingHandler`, `GetShipmentUnitsHandler`). Es deuda previa a
este cambio; el patrón correcto ya existe en el package (`Application\Contract\...Reader` +
`Infrastructure\ReadSide\Db...Reader`).

**Conclusión para el read-side:** la vista principal por token (`GetPublicTrackingSnapshot`) **no tiene
ningún acople**: solo lee `tracking_shipments`. El único acople a datos del camino público es la
etiqueta de los productos (`shipment_units`), en `GetPublicUnitsHandler`.

### 6.2 Mapa de acoples — otros hacia tracking

| Quién | Archivo:línea | Forma |
|-------|---------------|-------|
| Registro del provider | `bootstrap/providers.php:43` | Composición |
| Rutas API | `routes/api/tracking.php:6-10` | Composición (controllers del package) |
| Reactores | `packages/logistics/outbox/config/logistics-outbox.php:33-41` | Outbox (registro de handlers) |
| Admin | `app/Providers/Filament/AdminPanelProvider.php:257` | Descubrimiento de recursos Filament |
| App Vue (página «Seguí tu pedido») | `app/Http/Controllers/App/TrackController.php:10-15` | En proceso, por `QueryBus` (consultas públicas) — ya sale del caché |
| Cockpit del envío | `app/Support/Cockpit/ShipmentHub.php:13-16` | En proceso, por `QueryBus` |
| Torre de Control (compartir enlace) | `app/Filament/Pages/ControlTower.php:227` | **Acople a datos**: lee `tracking_shipments.token` |
| Cliente 360 | `app/Support/Cockpit/CustomerHub.php:206`, `:296` | **Acople a datos**: lee `tracking_satisfaction_surveys` |
| Variables de notificación | `packages/logistics/notifications/src/Infrastructure/Variable/ShipmentNotificationVariableProvider.php:219` | **Acople a datos**: lee `tracking_shipments` (token, paradas, ETA) |
| Load test / demo / restore | `app/Console/Commands/LoadTestPrepareCommand.php:340`, `LoadTestCleanupCommand.php:58-62`, `DemoOrderCommand.php:189-190`, `RestoreDrillCommand.php:46` | Acople a datos (operación; los `DELETE` no invalidan el caché) |
| Seeders | `database/seeders/TodayScenarioSeeder.php:68-71`, `TestDataVarietySeeder.php:48-49`, `DatabaseSeeder.php:33` | Comandos por bus + `TrackingRecord` directo |
| Tests de execution | `packages/logistics/execution/tests/Feature/NodeReceptionShelfLocationTest.php:12-13` | Solo tests |

### 6.3 Qué falta para que el camino público sea 100 % extraíble

1. **Productos por token** (`GetPublicUnitsHandler`): hoy hace `findByToken` + `tracking_units` +
   `shipment_units` en cada visita a la página. Paso siguiente: guardar la etiqueta del producto en
   `tracking_units` al proyectar (se elimina el acople a `shipment_units`) y cachear la lista por
   `shipment_id`, invalidando en un decorador de `UnitTrackingRepository` (mismo patrón que acá).
2. **Estado de la encuesta** (`GetPublicSurveyStateHandler`): un `findByToken` + un `EXISTS`. Mismo
   tratamiento, invalidando al grabar la encuesta.
3. **Credenciales de canal** (sección 4, puntos 2 y 3).

Hasta entonces la página HTML sigue haciendo esas lecturas (a la réplica) cuando no la resuelve el
navegador, el CDN o el micro-caché.

### 6.4 Pasos para la extracción física (`tracking-read`)

El read-side ya tiene su costura: el puerto `PublicTrackingReader`. Extraerlo no reescribe nada.

1. **Rol de servicio.** En `TrackingServiceProvider::boot()`, condicionar el registro de rutas (cuando
   exista `App\Support\ServiceRole`; hoy no se depende de él):
   - `ServiceRole::serves('tracking-read')` → `registerPublicReadRoutes()` (página `/t/{token}`).
   - `ServiceRole::serves('tracking')` → `registerPublicWriteRoutes()` (encuesta), comandos de consola,
     reactores.
   - En `routes/api/tracking.php`, la puerta `channels` (los dos `GET` públicos) va con
     `tracking-read`; las puertas `app` e `integrations`, con `tracking`.
   - `registerPublicReadSide()` (caché + lector) se registra en **ambos**: el lado de escritura lo
     necesita para invalidar.
2. **Desplegar `tracking-read` como unidad aparte**: misma imagen, otro rol; solo necesita Valkey y la
   réplica (`DB_HOST` apuntando a la réplica, sin credenciales de escritura). No corre outbox ni
   scheduler. Escala horizontal independiente; es el candidato natural para Octane.
3. **Ruteo en el borde**: nginx/LB manda `/t/*` y `/api/v1/channels/tracking/*` (solo `GET`) al upstream
   `tracking-read`; `POST /t/{token}/survey` sigue yendo al monolito.
4. **Cortar el último acople a datos** del camino público (6.3, punto 1) antes de quitarle a
   `tracking-read` el acceso a las tablas de shipments.
5. **Invalidación entre procesos**: mientras compartan Valkey, no cambia nada (el lado de escritura
   borra la clave). Si algún día no lo comparten, la invalidación pasa a ser un consumidor del evento
   `tracking.status_changed` del outbox en `tracking-read`.
6. **Base propia (opcional, último paso)**: `tracking-read` con su propia tabla de proyección alimentada
   por el outbox sobre transporte de red (ADR 001/008). Solo si el volumen lo justifica.

## 7. Antigüedad máxima percibida

Para un **cambio de estado** (lo que se invalida por evento):

| Capa | Suma |
|------|------|
| Valkey | 0 s en el caso normal |
| Micro-caché nginx (si está activo) | hasta 5 s |
| Navegador / CDN que respeta `max-age` | hasta 20 s (y puede mostrar un valor vencido una vez más mientras revalida, hasta +30 s) |

Para **posición del camión y ETA** (no se invalidan): lo anterior más `ttl_fresh` (20 s) y, con poco
tráfico, un poll adicional (la primera consulta tras vencer la ventana fresca recibe el valor guardado
y dispara el refresco).

Casos a conocer:

- **Retraso de réplica.** Tras invalidar, si un lector consulta antes de que el cambio llegue a la
  réplica, repuebla el caché con el estado anterior y lo deja fresco `ttl_fresh` segundos (más un poll).
  La ventana es el lag de replicación (milisegundos en condiciones normales). Mitigación si se observa:
  bajar `ttl_fresh`, o leer del primario durante unos segundos tras una invalidación.
- **`position_is_live`** se calcula al armar la foto, no al servirla: puede decir "en vivo" hasta
  `ttl_fresh` segundos de más.
- **Vencimiento del enlace** (72 h desde la entrega): puede servirse hasta `ttl_fresh` segundos después
  del instante exacto de vencimiento (más un poll).
- **Escrituras fuera del repositorio**: hasta `ttl_stale` en el peor caso (ver sección 3).
- **Valkey caído**: no hay caché; todas las lecturas van a la base. Funciona, pero sin protección.

## 8. Configuración

`packages/logistics/tracking/config/logistics-tracking.php` (defaults funcionales sin definir nada):

| Variable | Default | Qué controla |
|----------|---------|--------------|
| `TRACKING_PUBLIC_CACHE_ENABLED` | `true` | Caché de la foto en Valkey |
| `TRACKING_PUBLIC_CACHE_STORE` | (store por defecto) | Store de caché a usar |
| `TRACKING_PUBLIC_CACHE_TTL_FRESH` | `20` | Ventana fresca (s): antigüedad máxima de posición/ETA |
| `TRACKING_PUBLIC_CACHE_TTL_STALE` | `120` | Ventana stale (s): vida máxima de la entrada |
| `TRACKING_PUBLIC_CACHE_TTL_NEGATIVE` | `15` | Caché negativo (s); se recorta a `ttl_fresh` |
| `TRACKING_PUBLIC_HTTP_CACHE_ENABLED` | `true` | Cabeceras `Cache-Control: public` |
| `TRACKING_PUBLIC_HTTP_MAX_AGE` | `20` | `max-age` (s) |
| `TRACKING_PUBLIC_HTTP_SWR` | `30` | `stale-while-revalidate` (s) |

Apagado de emergencia: `TRACKING_PUBLIC_CACHE_ENABLED=false` (y `config:cache`). Las entradas que
queden en Valkey vencen solas en `ttl_stale`.

## 9. Tests

- `packages/logistics/tracking/tests/Feature/PublicTrackingReadCacheTest.php`: segunda lectura sin
  consultas; invalidación por cambio de estado; la posición no invalida; caché negativo y su caída al
  crear el seguimiento; token inválido; caché apagado; cabeceras solo en la ruta pública; 304; página
  HTML sin cookies y fuera de `web`.
- `packages/logistics/tracking/tests/Unit/PublicTrackingSnapshotTest.php`: ida y vuelta de la forma
  primitiva, sin objetos ni datos personales, ETag estable, rechazo de entradas malformadas.
